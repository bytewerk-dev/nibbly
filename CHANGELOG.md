# Changelog

All notable changes to Nibbly are documented in this file. The project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.4] — 2026-06-18

### Added
- Added the polished Dashboard and Visual Editor refinements from the active
  site build: structured backup previews, a wider AI tools layout with image
  compression grouped by output format, separate save/save-and-exit editor
  actions, scoped visual-editing highlights, and responsive admin-bar behavior.
- Added a live title search field to the dashboard page list so editors can
  filter pages immediately by typing part of a page title.
- Added `Cmd+S` / `Ctrl+S` as a save shortcut in the Visual Editor and
  Dashboard, matching the existing save-button behavior.
- Added an optional filename field to the AI image generator so editors can set
  the generated media filename before running the request, with a prompt-based
  filename suggestion button as a lightweight fallback.
- Added Media Library file renaming with extension-safety checks, including an
  optional JSON content scan that warns editors when the old filename appears in
  page/content files before the rename is confirmed.
- Added a localhost-friendly AI image job worker path for PHP's built-in
  development server so queued image jobs can be handed off to a CLI worker
  instead of keeping the dashboard request open for the whole generation.
- Added dashboard list summaries and pagination for pages, news, events,
  messages, trash views, the Icon Manager, and the Media Library, with separate
  configurable per-page limits for regular dashboard lists, the Icon Manager,
  and the Media Library.
- Added multi-file uploads to the Media Library so editors can select or drop
  several files at once.

### Changed
- Refined the Dashboard settings layout for list pagination and editor button
  styling with grouped fieldsets and a three-column pagination settings row.

### Fixed
- Dashboard editor section navigation now lists top-level field groups on
  custom-layout pages that do not use a `sections[]` array.
- Visual Editor sessions now use sliding activity refreshes and a CSRF-protected
  keepalive request so long editing sessions stay authenticated while editors
  interact with fields before saving.
- OpenRouter image generation now restricts GPT Image 2 models such as
  `openai/gpt-5.4-image-2-20260421` to the supported `1K`/`2K` image-size
  values in both the dashboard dropdown and server-side request config.

## [1.5.3] — 2026-06-17

### Changed
- Replaced remaining native browser `alert()`, `confirm()`, and `prompt()`
  flows in dashboard/editor UI with Nibbly modal dialogs, including backup
  previews, WYSIWYG link insertion, AI audit confirmation, image history
  clearing, backup restore mode selection, image-manager confirmation fallback,
  and the visual editor navigation guard.
- Extended the shared dashboard confirmation modal so it can safely render
  structured form and preview content, while preserving the existing text-only
  confirm behavior.
- Standard JSON pages continue to render through the shared header/footer and
  global style pipeline so site navigation, footer, fonts, colors, and theme
  variables stay consistent with the homepage.
- Editor dropdowns, inline editor modal saves, editor toasts, and stale login
  timeout notices now use the shared Nibbly UI behavior: dropdowns render above
  modals, section edits patch the DOM immediately after modal save, toasts use
  the top-right branded placement, and old `?timeout=...` URLs no longer show a
  stale session-expired notice.

## [1.5.2] — 2026-06-15

### Added
- **More icon libraries**: Phosphor, Iconoir, Ionicons, Myna UI, and TDesign Icons
  added to the Icon Library import dialog (all MIT-licensed, via Iconify).
- **Custom icon-set picker**: replaced the native `<select>` in the import modal
  with a custom dropdown so it renders correctly above the modal overlay in all
  browsers.

### Fixed
- Icon-set dropdown was clipped by the modal's `overflow-y: auto` container;
  now uses `position: fixed` so it is never cut off.
- `nb-combobox__list` z-index raised to `--nb-z-dropdown` (1000); all modal
  open calls now close any open combobox first.

## [1.5.1] — 2026-06-14

### Added
- **Server-side image conversion**: OpenRouter image jobs now convert the
  returned PNG to the selected format (JPEG / WebP) server-side using GD, with
  a 0–100 % quality slider (default 70 %) in the image generator UI.
- **Estimated cost per image**: the image generator shows an estimated cost next
  to the computed image size — live pricing from the OpenRouter Models API for
  OpenRouter, curated defaults for OpenAI-compatible providers, hidden for
  Anthropic (no image support).
- **Multi-thumbnail history**: Recent Generations cards now show all thumbnails
  side by side when more than one image was generated in a single request.
- **Dev login fallback**: on localhost, `admin` / `dev` always grants access
  even if no user named "admin" exists in `users.json`.

### Fixed
- Fill-slider thumb now spans the full height of the field.
- JS error on load when `AI_OPENAI_IMAGE_COST_CENTS` was referenced before
  declaration.

## [1.5.0] — 2026-06-13

### Added
- **Frontend AI Assistant (Copilot)**: logged-in editors get a page-aware chat
  widget in the visual editor and dashboard. It builds a safe, server-generated
  page context, proposes structured field changes, and applies them only after
  explicit confirmation. The AI is treated as untrusted: every write runs
  through server-side validation, HMAC-signed proposals, a strict HTML/link
  allowlist, per-user chat history, role permissions, burst limits, and audit
  logging. New API actions cover context, chat, suggestions, HTML formatting,
  visibility toggles, apply/undo, content drafting/creation/publishing, and
  image generation (`ai-copilot-*`).
- **Streaming Copilot chat**: assistant replies stream token-by-token over
  Server-Sent Events (`ai-copilot-chat-stream`), with an automatic fallback to
  the buffered JSON endpoint when streaming is unavailable.
- **Anthropic (Claude) provider**: AI settings add Anthropic alongside the
  OpenAI-compatible and OpenRouter providers. Requests are translated to and
  from the Messages API, including streaming; image generation remains on the
  image-capable providers.
- **Per-feature model routing**: full content drafts use the quality chat model
  while field suggestions and SEO text can use a cheaper, faster text model;
  the AI settings hint explains the split.
- **Live OpenRouter model list**: a server-side, 24h-cached `ai-openrouter-models`
  action feeds the model suggestions and image-model options, and can pre-fill
  the cost-estimate fields from real OpenRouter pricing.
- **Translate field action**: the Copilot can translate a page into another
  configured language (`ai-copilot-translate`), detecting the target language
  from the instruction and proposing signed, reviewable field updates on the
  counterpart page.
- **Content audit**: a dashboard tool scans all pages for missing/weak SEO
  descriptions and images without alt text, then offers AI-suggested
  descriptions that are applied with a backup
  (`ai-content-audit`, `-suggest`, `-apply`).
- **Background image jobs**: AI image generation runs as tracked jobs
  (`ai-image-jobs`, `ai-image-job-run`) and detaches via
  `fastcgi_finish_request()` where available, so generation survives page
  navigation without requiring server cron; it falls back to synchronous
  execution otherwise.
- **AI usage panel**: AI settings show this month's requests, estimated cost,
  token totals, and a monthly-budget progress bar.
- **Finder-style media library**: the media manager gains a folder sidebar that
  drives both grid and list views consistently, with per-folder counts and
  inline folder create/delete; an in-app folder-name prompt replaces the native
  browser prompt so it also works in embedded webviews.
- **Custom select component (`nb-select`)**: native `<select>` dropdowns across
  the dashboard and visual editor are progressively enhanced into token-styled,
  keyboard-accessible comboboxes that render identically in all browsers,
  including Safari and embedded webviews.
- **Test suite**: added smoke tests for the Copilot API surface, Copilot JS,
  Copilot i18n coverage, the i18n catalog, and the media manager (`tests/`).

### Changed
- **License file**: the `LICENSE` file now contains the Mozilla Public License
  2.0 text. The relicensing was announced in the 1.4.0 notes; this release ships
  the actual license file.
- **Self-healing AI requests**: the gateway reassembles unexpected streaming
  (SSE) responses into the expected JSON and retries once instead of failing,
  so a provider that ignores `stream:false` no longer breaks a request.
- **Reference image handling**: AI image generation passes uploaded/library
  reference images to OpenRouter in the documented `image_url` data-URL format,
  and reinforces the requested aspect ratio (both via `image_config` and a
  prompt instruction) so the user's selection is respected over the reference's
  framing.
- **Admin UI consistency**: unified control heights (40px in forms, 36px in
  modal rows, 32px in the media toolbar, 30px in list headers and the editor
  topbar), provider settings that wrap cleanly on narrow viewports, list-row
  action icons grouped as a tidy grid, dropdown options spaced so hover states
  do not merge, and SEO tooltips fixed for dark mode.

### Fixed
- **News post `</script>` breakout**: admin-only inline news JSON and the public
  JSON-LD block are encoded with `JSON_HEX_TAG`/`JSON_HEX_AMP` so content cannot
  terminate the surrounding script tag.
- **Leaked cURL handle**: the streaming provider request now closes its cURL
  handle on every retry iteration.
- **Empty media folder deletion**: folders containing only OS metadata files
  (`.DS_Store`, `Thumbs.db`, `desktop.ini`) can be deleted; the metadata is
  cleaned up with the folder.
- **Media folder filtering**: selecting a folder now filters both grid and list
  views, and the "Main folder" (root) option is no longer coerced to "All".

## [1.4.1] — 2026-06-02

### Added
- **JSON-backed forms**: public form definitions can now live in
  `content/forms/*.json`, render through `includes/forms.php`, lazy-load through
  `api/form.php`, and submit through the new `api/form-submit.php` endpoint.
- **Forms settings panel**: admins can manage simple forms under
  **Settings -> Forms**, including label, description, active state, local
  storage, email notification, subject template, success text, field type, key,
  label, placeholder, required state, width, and select/radio options.
- **Multiple form inbox support**: messages now carry `formId`, `formLabel`, and
  structured `fields[]` metadata; the inbox can filter by form and shows the
  source form in the list and detail view.
- **Admin UX documentation**: README, architecture reference, AI agent guide,
  and skills docs now describe JSON forms and the recent dashboard interaction
  patterns.

### Changed
- **Legacy contact submissions**: `api/contact.php` now stores compatible form
  metadata so old and new submissions can be shown consistently in the inbox.
- **Form lazy endpoint**: `api/form.php` renders JSON forms when a matching
  definition exists before falling back to whitelisted legacy form partials.
- **Admin forms UI**: the Forms panel now uses a clear select control for form
  switching, compact non-overflowing field rows, normal label casing, aligned
  checkboxes, and consistent delete icon buttons.
- **Admin stylesheet loading**: `admin/dashboard.php` cache-busts
  `admin/style.css` with `filemtime()` so CSS fixes appear after reload.
- **Content editor usability**: the sections jump menu is sticky, wraps/clamps
  long labels, supports search/type filtering, greys out filtered items, and
  keeps keyboard section navigation more stable.
- **Dashboard and settings polish**: branding/media transparent previews are
  easier to see in dark mode, primary-button preview shows the configured glow,
  dashboard analytics numbers align at the bottom of cards, and the messages
  page explains that entries come from public forms.
- **AI dashboard visibility**: disabled AI features no longer show inactive
  tools; unconfigured/disabled AI can be represented by a small dismissible
  notice instead of a full unusable panel.

### Fixed
- **Forms reload state**: directly loading or refreshing
  `#settings/forms` keeps the selected form and its field rows visible.
- **Filtered section inserts**: filtering section types no longer leaves
  unrelated insert controls taking space between visible blocks.

## [1.4.0] — 2026-05-23

### Added
- **Multiple contact recipients**: contact form delivery now accepts comma-separated
  recipient lists for primary recipients.
- **BCC contact recipients**: email settings include optional blind-copy
  recipients, with matching SMTP and PHP mail delivery support.
- **Shared news post renderer**: language news detail pages now use a single
  reusable Core renderer instead of duplicating post template logic into every
  generated language wrapper.

### Changed
- **Project license**: Nibbly is now licensed under the Mozilla Public License
  2.0 starting with this release. Earlier releases up to and including 1.3.2
  remain available under the MIT License.
- **News routing**: Apache and the PHP development router now route news detail
  URLs through the shared renderer before language-local listing templates can
  intercept them.
- **Email settings validation**: recipient and BCC fields now normalize
  comma-separated address lists, reject invalid addresses, and keep the sender
  address validation separate.
- **Starter content**: generated news-post wrappers now delegate to the shared
  Core renderer, reducing duplicated update-sensitive code in site templates.

### Fixed
- **SMTP delivery**: the SMTP mailer now sends one envelope recipient per
  primary/BCC address and omits BCC recipients from message headers.
- **Sendmail BCC fallback**: PHP mail delivery now sends configured BCC copies
  alongside the primary contact form message.
- **Admin footer dependencies**: logged-in admin pages now load block rendering
  helpers when the footer needs editor metadata.

### Migration notes
- Existing `admin/config.php` files keep their current `NIBBLY_VERSION` value
  until manually updated or regenerated by setup.
- Existing releases through 1.3.2 remain MIT licensed. From 1.4.0 onward,
  modified Nibbly Core files distributed by third parties must follow MPL-2.0.
- Existing single-recipient email settings remain valid. Multiple primary and
  BCC recipients can be added as comma-separated lists.
- Existing generated language news-post wrappers continue to work, but newly
  generated wrappers delegate to `includes/news-post.php`.

### Changed files
- `.htaccess`
- `CHANGELOG.md`
- `LICENSE`
- `README.md`
- `admin/api.php`
- `admin/config.example.php`
- `admin/dashboard.php`
- `admin/lang/*.json`
- `admin/setup.php`
- `admin/starter-content.php`
- `api/SmtpMailer.php`
- `api/contact.php`
- `includes/footer.php`
- `includes/news-post.php`
- `includes/version.php`
- `route.php`
- `router.php`
- `website/content/news/*.json`
- `website/en/docs.php`

## [1.3.2] — 2026-05-22

### Added
- **Multilingual editor field tabs**: Core editor dialogs can now group
  language-specific fields such as `title.de` / `title.en` or fields marked
  `localized: true` into per-language tabs while keeping non-language fields
  outside the tab group.
- **AI-assisted dialog translation**: When the AI module is enabled,
  multilingual editor tabs include a translate action that uses the currently
  active language as the source and fills the other language fields inside the
  open dialog only.
- **Editor field documentation**: The AI agent guide now documents the
  declarative multilingual field metadata and language-suffixed path
  conventions for reusable Core editor dialogs.

### Changed
- **Dashboard event editor**: Event translation fields now use the generic
  language-tab UI, support keyboard-accessible tabs, hide tabs on one-language
  sites, and include an explicit cancel button beside Save.
- **AI module handling**: Dashboard and editor AI settings/translation requests
  now respect the global AI module flag and avoid background AI settings loads
  when the module is disabled.

### Fixed
- **Dashboard AI visibility**: The dashboard home AI section is no longer
  rendered at all when `settings.modules.ai=false`, preventing disabled AI tools
  and configuration warnings from appearing.
- **Hidden dashboard sections**: Admin CSS now defensively preserves
  `[hidden]` behavior for dashboard sections even when component display rules
  set flex layouts.

### Migration notes
- Existing `admin/config.php` files keep their current `NIBBLY_VERSION` value
  until manually updated or regenerated by setup.
- Existing editor schemas continue to render unchanged unless they use
  language-object values, language-suffixed field paths, or opt into
  `localized: true` / `multilingual: true` / `translatable: true`.
- AI translation buttons appear only when the AI module is enabled. Generated
  translations are persisted only after the normal dialog Save action.

### Changed files
- `AI-AGENT-GUIDE.md`
- `CHANGELOG.md`
- `README.md`
- `admin/api.php`
- `admin/config.example.php`
- `admin/dashboard.php`
- `admin/lang/de.json`
- `admin/lang/editor-de.json`
- `admin/lang/editor-en.json`
- `admin/lang/en.json`
- `admin/setup.php`
- `admin/style.css`
- `css/inline-editor.css`
- `includes/content-loader.php`
- `includes/footer.php`
- `includes/version.php`
- `js/inline-editor.js`

## [1.3.1] — 2026-05-19

### Added
- **Login page visual controls**: Admin login settings now support optional
  favicon/logo branding, background/left/right image layouts, background
  overlays with adjustable opacity, optional login box rendering, and custom
  login box background and text colors.
- **Maintenance/coming soon visual controls**: Maintenance mode now supports
  favicon/logo branding plus background, left-image, and right-image layouts
  with configurable overlay color and opacity.

### Changed
- **Password warning banner**: The weak-password warning now uses the same
  compact banner pattern as email and AI settings warnings, with dashboard
  content-aligned spacing and a shorter action label.
- **Login visual polish**: The login version label follows the configured login
  text color at 80% opacity, and overlay color controls keep the opacity slider
  on the same row as the color picker.
- **Documentation**: README, architecture notes, schema, and the AI agent guide
  document the new login and maintenance visual settings.

### Fixed
- **Maintenance expiry**: Maintenance mode continues to be evaluated on each
  frontend request, so an expired `until` time stops blocking the public site
  without requiring an admin save.

### Migration notes
- Existing `admin/config.php` files keep their current `NIBBLY_VERSION` value
  until manually updated or regenerated by setup.
- Existing login and maintenance settings remain valid. New visual fields use
  conservative defaults when absent.

### Changed files
- `AI-AGENT-GUIDE.md`
- `CHANGELOG.md`
- `README.md`
- `admin/api.php`
- `admin/config.example.php`
- `admin/dashboard.php`
- `admin/index.php`
- `admin/lang/de.json`
- `admin/lang/en.json`
- `admin/setup.php`
- `admin/style.css`
- `architecture.md`
- `content/schema.json`
- `includes/access-guard.php`
- `includes/version.php`

## [1.3.0] — 2026-05-19

### Added
- **AI gateway and provider settings**: Nibbly now has a server-side AI gateway
  with provider-specific credentials, OpenAI-compatible endpoints, OpenAI and
  OpenRouter presets, optional local/private provider URLs, feature flags,
  quotas, token caps, cost estimates, usage counters, and audit logging.
- **Dashboard AI tools**: the dashboard includes an assistant, text generator,
  image generator, image-to-image support with multiple reference images, prompt
  improvement, selectable image models, resolution/orientation controls, and
  recent image-generation history.
- **Generated image storage**: generated images are saved into
  `assets/images/generated/`, indexed in `content/ai-image-history.json`, and
  available through the Media Library.
- **Privacy-friendly analytics dashboard**: site analytics now include compact
  dashboard summaries, charts, daily/hourly/monthly/yearly views, bot filtering,
  and automatic aggregation of detailed records after 90 days.
- **Dashboard module controls**: News, Events, Messages, Icon Manager, and AI
  features can be disabled from Settings so their admin UI is hidden.
- **Media Library folders**: the Media Library is now a dashboard panel with
  one-level folders, folder move controls, list/grid views, and keyboard-aware
  lightbox navigation.

### Changed
- **Dashboard home**: the login landing view now combines status chips, AI
  tools, analytics, and recent site information in a clearer dashboard layout.
- **Settings navigation**: settings are grouped into Site, Features, Users, and
  System sections with a dedicated Modules panel and renamed Reset area.
- **Admin theming**: the default admin theme is dark, color tokens are more
  consistent across light/dark mode, hints have stronger contrast, and badge
  indicators use the configured primary color.
- **AI image provider handling**: OpenAI image edits use multipart image
  uploads, OpenRouter image models are selected with provider-specific model
  IDs, and transient TLS/read errors are retried only when no response bytes were
  received.
- **Localization coverage**: all backend language files now share the same key
  set as English, and editor language files are aligned with `editor-en.json`.
- **Documentation**: README, architecture notes, and the AI agent guide now
  document AI providers, safeguards, generated image storage, usage/audit files,
  and the required gateway usage pattern.

### Fixed
- **AI image errors**: provider failures in the image generator now render as
  readable status banners instead of raw inline text.
- **OpenAI image-to-image**: reference images are sent in the expected multipart
  structure instead of JSON string payloads.
- **Generated image previews**: dashboard image previews and Media Library
  lightbox handling preserve image aspect ratios more reliably.
- **Admin UI polish**: several spacing, dark-mode contrast, field alignment,
  textarea resizing, date-picker icon, and AI control layout issues were
  corrected across dashboard, content, news, events, messages, and settings.

### Migration notes
- Existing `admin/config.php` files keep their current `NIBBLY_VERSION` value
  until manually updated or regenerated by setup.
- AI configuration is stored in `content/ai-settings.json`; API keys are kept
  server-side and are not returned by `load-ai-settings`.
- Generated image metadata is stored in `content/ai-image-history.json`; request
  usage and audit data live in `content/ai-usage.json` and
  `content/ai-audit/YYYY-MM-DD.jsonl`.
- Local/self-hosted AI provider URLs require the explicit local-provider setting
  before they are accepted.

### Changed files
- `AI-AGENT-GUIDE.md`
- `CHANGELOG.md`
- `README.md`
- `admin/api.php`
- `admin/config.example.php`
- `admin/dashboard.php`
- `admin/index.php`
- `admin/lang/*.json`
- `admin/setup.php`
- `admin/style.css`
- `architecture.md`
- `css/image-manager.css`
- `css/nibbly-admin-tokens.css`
- `includes/access-guard.php`
- `includes/ai/ai-helper.php`
- `includes/analytics-helper.php`
- `includes/content-loader.php`
- `includes/email-obfuscator.php`
- `includes/footer.php`
- `includes/header.php`
- `includes/version.php`
- `js/image-manager.js`
- `js/inline-editor.js`

## [1.2.5] — 2026-05-16

### Added
- **Frontend access controls**: maintenance mode, launch/back-soon messaging,
  countdown support, session-based preview bypass links, and per-page password
  protection are now enforced server-side.
- **Email obfuscation**: public email addresses can be replaced with
  JavaScript-decoded placeholders to reduce simple bot harvesting.
- **SEO/AEO/GEO tooling**: per-page metadata, answer summaries, canonical URLs,
  robots settings, Open Graph metadata, default Open Graph image fallback,
  sitemap/robots output, and SEO health indicators are now available in the
  editor and frontend admin bar.
- **Accessibility foundation**: skip links, landmark/navigation ARIA labels,
  reduced-motion handling, dialog semantics, focus handling, live regions, and
  alt-text health checks improve keyboard and screen-reader behaviour.

### Changed
- **Content Editor page settings**: basics, access, navigation, and SEO/AEO
  controls are grouped into clearer cards with a more compact, scan-friendly
  layout.
- **Section editing UX**: standard sections are open by default, image sections
  use a two-column layout with larger thumbnails, and text heading-level
  controls are aligned next to titles.
- **Access settings UX**: maintenance, bypass, and privacy controls have a more
  compact layout with localized English defaults and clearer spacing.
- **Branding settings**: favicon, PNG fallback, frontend logos, admin logo, and
  default Open Graph image fields now show recommended image dimensions.
- **Documentation**: README, AI agent guide, architecture reference, and the
  presentation website docs now document access controls and accessibility best
  practices.

### Fixed
- **Admin login warning noise**: session cookie handling no longer emits visible
  warnings when the login page is opened with an existing session.
- **Password page theme**: password-protected page screens now respect the
  visitor's light/dark theme preference.
- **SEO navigation**: frontend SEO health badges link directly to the matching
  Content Editor page, and SEO health is also shown in the editor and page list.
- **Accessibility status output**: editor/dashboard toasts and SEO health badges
  now expose status details to assistive technologies.

### Migration notes
- Existing `admin/config.php` files keep their current `NIBBLY_VERSION` value
  until manually updated or regenerated by setup.
- To enforce private access on custom PHP templates, set `$contentPage` before
  including `includes/header.php` so the access guard can load the matching page
  JSON.
- Maintenance bypass keys and page passwords are stored as hashes. Do not write
  plaintext secrets directly into JSON files.
- Custom templates should preserve the new accessibility hooks: one `<main>`
  target, meaningful heading order, visible focus states, useful alt text, and
  reduced-motion fallbacks for custom animations.

### Changed files
- `AI-AGENT-GUIDE.md`
- `CHANGELOG.md`
- `README.md`
- `admin/api.php`
- `admin/config.example.php`
- `admin/dashboard.php`
- `admin/lang/de.json`
- `admin/lang/en.json`
- `admin/setup.php`
- `admin/style.css`
- `architecture.md`
- `css/inline-editor.css`
- `css/style.css`
- `includes/access-guard.php`
- `includes/email-obfuscator.php`
- `includes/footer.php`
- `includes/header.php`
- `includes/page.php`
- `includes/seo-helper.php`
- `includes/version.php`
- `index.php`
- `js/email-obfuscator.js`
- `js/image-manager.js`
- `js/inline-editor.js`
- `route.php`
- `router.php`
- `website/en/docs.php`

## [1.2.4] — 2026-05-15

### Added
- **Structured group editor fields**: inferred link, image, textarea, and icon
  field types now receive purpose-built controls in grouped editing modals.
- **One-page menu mode**: menus can opt into one-page navigation with normalized
  hash links on language subpages and client-side active section tracking.
- **Local development login**: loopback-only `admin` / `dev` login is available
  for existing admin users and can be disabled via `NIBBLY_DEV_LOGIN`.

### Changed
- **Editable group helpers**: structured frontend lists can now use shared
  group metadata helpers so cards, links, images, and multi-field items open the
  right editor without custom attribute wiring.
- **Inline editor targeting**: grouped cards and link-heavy content now use
  clearer block hitboxes, unified selection highlighting, and stricter click
  priority between inline fields, links, and group modals.
- **Single-field list items**: plain single-field items stay inline-editable
  without forcing unnecessary modal editing.

### Fixed
- **Visual Editor controls**: overlay toolbar hover states, editor tooltips, and
  add-section feedback are more consistent across editor surfaces.
- **Inserted block visibility**: newly inserted blocks now start with visible
  placeholder content instead of rendering as empty, hard-to-find sections.
- **Event editing**: event item edit buttons now open the event modal directly.
- **Toast fallback**: missing editor translations no longer expose raw toast
  keys for section insertion.

### Migration notes
- Existing `admin/config.php` files keep their current `NIBBLY_VERSION` value
  until manually updated or regenerated by setup.
- `NIBBLY_DEV_LOGIN` is guarded by loopback host and client checks. Set it to
  `false` in local config files when the fallback login should be disabled.
- Custom structured lists should prefer `editableListGroupItemAttrs()` for
  grouped item editing; plain single-field lists can remain inline-only.

### Changed files
- `AI-AGENT-GUIDE.md`
- `CHANGELOG.md`
- `README.md`
- `SKILLS.md`
- `admin/config.example.php`
- `admin/index.php`
- `admin/setup.php`
- `admin/starter-content.php`
- `cli/convert.php`
- `content/menus.json`
- `css/inline-editor.css`
- `css/style.css`
- `includes/block-types.php`
- `includes/content-loader.php`
- `includes/footer.php`
- `includes/header.php`
- `includes/menu-helpers.php`
- `includes/version.php`
- `js/inline-editor.js`

## [1.2.3] — 2026-05-12

### Added
- **Icon Manager**: dashboard section for browsing, editing, renaming,
  deleting, validating, cleaning up, and importing SVG icons from a whitelisted
  Iconify source list.
- **Editable group controls**: optional grouped editing for custom frontend
  structures such as feature cards, icon/title/text/link groups, and similar
  composite content.
- **Media Library foundation**: the former image manager now supports media
  categories for images, audio, video, documents, and PDFs while existing image
  picker workflows can still restrict the dialog to images.
- **Media trash folders**: deleted media can be moved to type-specific trash
  folders before permanent deletion.
- **Bot-protected forms**: contact forms can lazy-load their submit endpoint and
  use one-time, file-backed form tokens without third-party tracking services.

### Changed
- **Dashboard navigation**: added dedicated entries for the Image Manager and
  Icon Manager, and made the displayed Nibbly version come from the shared core
  version helper.
- **Icon rendering**: icon keys resolve through an upgrade-safe iconset JSON
  with a default fallback when a referenced icon does not exist.
- **Editor UX**: group and section taskbars use a more consistent icon-only
  control layout while preserving direct inline editing of grouped fields.
- **Starter/demo content**: component and block showcase text now references the
  current render helper names and includes fuller examples for list, audio, and
  SoundCloud blocks.
- **Footer defaults**: new setups no longer duplicate the site name as the
  default footer tagline; the footer layout now treats the site name as its own
  brand element.

### Fixed
- **Image manager trash view**: trash previews and lightbox previews resolve
  trashed assets correctly, and selection-only controls are hidden where they do
  not apply.
- **Media manager colors**: manager buttons and active states use the configured
  primary color instead of hard-coded blue accents.
- **SVG cleanup**: cleanup removes unsupported wrappers, empty definitions, and
  unsafe SVG parts more reliably while preserving the required viewBox metadata.
- **Bootstrap Icon imports**: imported SVGs keep usable sizing/viewBox data so
  icons no longer render as tiny fragments in the preview.
- **Base theme polish**: component buttons, testimonials, event cards, quote
  blocks, list blocks, and compact audio embeds have more consistent spacing and
  hover states.

### Migration notes
- Existing `admin/config.php` files keep their current `NIBBLY_VERSION` value
  until manually updated or regenerated by setup.
- Custom icon sets live in `content/settings/iconset.json`; if an icon key is
  renamed or deleted, existing content that still references the old key falls
  back to the default icon.
- Media trash folders are created under `assets/*-trash/` for supported media
  types. Permanent deletion from the trash remains destructive.

### Changed files
- `.gitignore`
- `AI-AGENT-GUIDE.md`
- `CHANGELOG.md`
- `README.md`
- `admin/api.php`
- `admin/config.example.php`
- `admin/dashboard.php`
- `admin/index.php`
- `admin/lang/*.json`
- `admin/lang/editor-*.json`
- `admin/setup.php`
- `admin/starter-content.php`
- `admin/style.css`
- `api/contact.php`
- `api/form.php`
- `assets/documents/.gitkeep`
- `assets/documents-trash/.gitkeep`
- `assets/videos/.gitkeep`
- `assets/videos-trash/.gitkeep`
- `content/settings/iconset.json`
- `css/components.css`
- `css/image-manager.css`
- `css/inline-editor.css`
- `css/style.css`
- `includes/block-renderers/list.php`
- `includes/block-renderers/soundcloud.php`
- `includes/contact-form.php`
- `includes/content-loader.php`
- `includes/default-iconset.json`
- `includes/footer.php`
- `includes/form-protection.php`
- `includes/header.php`
- `includes/pricing-contact-form.php`
- `includes/version.php`
- `js/image-manager.js`
- `js/inline-editor.js`

## [1.2.2] — 2026-05-07

### Fixed
- **Visual Editor overlay controls**: section and list item toolbars now stay
  reachable when they are positioned above small editable areas.
- **Visual Editor block defaults**: editor select fields now use
  `BlockTypeRegistry` defaults when older JSON sections omit optional fields,
  so missing `text.titleTag` and `heading.level` open as `h2` instead of `h1`.
- **Inline rich-text link insertion**: saving a link from the floating toolbar
  now preserves the inline insert state instead of falling through to the
  editable-link save path.
- **Visual Editor undo/redo**: section reorder undo and redo now apply the
  correct inverse/forward directions.
- **Visual Editor structural undo**: adding sections now records the undo
  snapshot before mutating the sections array.
- **Editable list insertion**: adding an item after an existing array-backed
  list item now keeps the list as an array instead of converting it to a
  numbered object.

### Migration notes
- Existing `admin/config.php` files keep their current `NIBBLY_VERSION` value
  until manually updated or regenerated by setup.

### Changed files
- `CHANGELOG.md`
- `README.md`
- `admin/config.example.php`
- `admin/setup.php`
- `css/inline-editor.css`
- `js/inline-editor.js`

## [1.2.1] — 2026-05-06

### Added
- **Web-cron trigger**: hosts without server cron support can call a secret
  dashboard-generated URL through services such as cron-job.org or EasyCron.
- **Scheduled backup execution mode**: the dashboard can now choose between
  server cron and web cron, and shows a copyable web-cron URL in the cron setup.

### Fixed
- **Contact form Reply-To handling**: submitted sender addresses are validated
  before being used as Reply-To headers, and invalid addresses are omitted.

### Changed
- **Backup documentation**: README, CLI documentation, and migration notes now
  describe the web-cron fallback for hostings without real cron jobs.

### Migration notes
- Installations that cannot use server cron can enable Web-Cron in the Backup
  settings and call `/api/backup-cron.php?token=...` once per day through an
  external scheduler.
- Existing `admin/config.php` files keep their current `NIBBLY_VERSION` value
  until manually updated or regenerated by setup.

### Changed files
- `CHANGELOG.md`
- `README.md`
- `admin/api.php`
- `admin/config.example.php`
- `admin/dashboard.php`
- `admin/lang/de.json`
- `admin/lang/en.json`
- `admin/setup.php`
- `admin/style.css`
- `api/backup-cron.php`
- `api/contact.php`
- `cli/README.md`
- `includes/backup-helper.php`

## [1.2.0] — 2026-05-06

### Added
- **Scheduled full-site backups**: cron-ready backup runner with lock file,
  status reporting, manual runs, tiered daily/weekly/monthly/yearly retention,
  and a storage limit for non-manual backups.
- **Remote backup targets**: upload scheduled or manual ZIP backups to Dropbox,
  Google Drive, Microsoft OneDrive, FTP/FTPS, SFTP/SCP, S3-compatible storage,
  and WebDAV.
- **OAuth broker support**: browser-based connect flows for Dropbox, Google
  Drive, and Microsoft OneDrive via a configurable Nibbly auth broker. These
  providers are marked as beta while provider approval status may limit
  availability for third-party users.
- **Remote backup management**: list, import, and delete remote backups where
  the provider supports it, plus per-row loading state for manual remote uploads.
- **Backup restore workflow**: upload a Nibbly backup ZIP and restore it from
  the dashboard.
- **Clean dashboard routes**: dashboard navigation now uses clean `/admin/dashboard`
  URLs with hash routes for tabs and settings subtabs.
- **FTP/FTPS support**: remote target for classic hosting FTP users, including
  passive mode and FTPS toggle.

### Changed
- **Backup archive naming**: generated ZIP filenames now include the site domain
  directly, without the previous `site-` prefix.
- **Remote storage layout**: remote uploads are placed in a per-site subfolder
  under the configured remote path to avoid collisions between multiple sites.
- **Backup settings UI**: local backups are shown directly below the manual
  backup/restore actions; scheduled backup settings, remote targets, and cron
  setup are grouped more clearly and respect light/dark mode.
- **Settings navigation**: the password tab is now "My Account" / "Mein Konto";
  settings subtabs no longer wrap and have stable hash routes.
- **Admin translations**: backup/remote strings and selected error messages are
  available across all bundled admin languages.
- **Deploy behavior**: website mirrors use the documented `--only-newer` option
  for "local newer wins" behavior.

### Migration notes
- Existing `admin/config.php` files keep their current `NIBBLY_VERSION` value
  until manually updated or regenerated by setup.
- Installations that use scheduled backups need a real server cron task pointing
  at `cli/backup.php --action=run`.
- SFTP/SCP targets require the PHP `ssh2` extension on the hosting server.
- FTP/FTPS targets require the PHP FTP extension.
- Dropbox, Google Drive, and Microsoft OneDrive one-click connection flows may
  be limited while the central OAuth apps are still in provider beta/testing or
  unverified-publisher status. Other remote target types do not depend on those
  provider approvals.

### Changed files
- `.gitignore`
- `AI-AGENT-GUIDE.md`
- `README.md`
- `admin/api.php`
- `admin/config.example.php`
- `admin/dashboard.php`
- `admin/index.php`
- `admin/setup.php`
- `admin/style.css`
- `admin/lang/cs.json`
- `admin/lang/de.json`
- `admin/lang/en.json`
- `admin/lang/es.json`
- `admin/lang/fr.json`
- `admin/lang/it.json`
- `admin/lang/pl.json`
- `admin/lang/pt.json`
- `admin/lang/tr.json`
- `architecture.md`
- `cli/README.md`
- `cli/backup.php`
- `css/nibbly-admin-components.css`
- `includes/backup-helper.php`

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
