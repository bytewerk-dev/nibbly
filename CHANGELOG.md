# Changelog

All notable changes to Nibbly are documented in this file. The project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Release dates use Europe/Vienna calendar dates. Historical 1.x dates are retained
as originally recorded; see [release history and policy](RELEASES.md) for verified
Git milestones and the gaps in publication records.

## Unreleased

### Added

- **Pop theme** (Settings → Design, `theme.preset: pop`), the sixth preset: a deep slate
  sidebar and topbar drawn from the base hue as a vertical gradient, a light stage with
  rounded white cards and soft shadows, legends, panel titles, stat and status labels in
  pill-shaped chips (stat tiles take turns through base, danger, warning, success and accent),
  round navigation with a glowing active pill, round colour swatches and tinted table heads.
  Picking the card brings Atkinson Hyperlegible Next,
  24 px button radius, glow and its own four-colour palette; every field stays free to change.
  Light and dark variants; the dark one lifts the colours and darkens the pill text.
- **Text size** (Settings → Design, `theme.fontSize`: small, default, large). Scales the
  dashboard's type steps only; spacing, controls and the sidebar keep their size, so the
  relation between text and elements stays as it is.
- **Three new local fonts**: IBM Plex Sans, Atkinson Hyperlegible Next and Public Sans
  (variable, latin and latin-ext, SIL Open Font License, shipped in `admin/fonts/`). Each
  theme brings its own: Soft Inter, Classic Public Sans, Paper Atkinson Hyperlegible, Studio
  IBM Plex Sans, Console Geist Mono.

### Fixed

- The breadcrumb in the topbar took the stage's muted text colour and was hard to read on
  the dark topbars of Classic and Pop; it now uses the topbar's own muted colour.
- The collapsible boxes in the AI agent settings keep their bottom padding when open, and the
  hover surface of their heading stays inside the rounded frame.

### Removed

- The Geist sans font. It was hardly distinguishable from Inter; a stored `geist` setting
  reads as IBM Plex Sans. Geist Mono stays for the Console theme.

## [2.4.1] — 2026-10-23

### Changed

- **Hover on primary buttons brightens briefly, then settles.** The fill colour lifts
  (lighter, a touch more saturated, derived in OKLCH from the effective primary colour) and eases
  to a calmer hover tone; leaving returns the rest colour; pressing answers at once. Secondary
  buttons, the floating AI button and the button preview share the same timing through tokens;
  the dashboard menu and the settings sub-navigation brighten monochrome, not in the accent,
  and keep their own text colour (white in Classic). Tokens (`--nb-hover-lift`, `--nb-hover-settle`,
  `--nb-hover-tint`, `--nb-duration-hover-in/out`, `--nb-ease-overshoot`); active menu items,
  disabled and loading buttons are left alone, and reduced-motion settings skip the overshoot.

### Fixed

- **"Glow" off is honoured everywhere.** With the glow disabled, real primary buttons (`.btn-primary`,
  `.nb-btn--primary`, the inline editor's buttons) lose their sheen, shadows and text shadow in every
  state, as the preview already did; keyboard focus rings stay. The glow itself is now a sheen
  over the button colour, so the hover colour animation works with and without it.

- Clicking an image in the visual editor opens the media library with that image selected
  (also the image field of the group dialog and the news cover), on the page that holds it and
  scrolled into view. Before, the library opened without a selection.
- Editable links keep their own display in the visual editor. The editor set every editable
  field to `display: inline`, so button links lost their width and top margin and slid over
  the text above them.
- The sidebar's collapse button shows the panel symbol that has become the convention for
  toggling navigation bars, without rotating when collapsed, and sits apart from the
  navigation under a divider in the sidebar's line style.
- The agent status in the AI settings no longer disappears for a moment when the dashboard
  overview fills the form before the full AI settings arrive.
- **Visits are counted even when a site's `includes/header.php` lacks the tracking call.**
  The router schedules the count as a fallback at the end of the request; whichever runs first
  counts, so nothing is counted twice. Sites with an older or customised header file showed
  "no page views yet" although the storage worked. Error pages (404) no longer count as views.

## [2.4.0] — 2026-10-20

### Added

- **Five dashboard themes instead of one look.** Settings → Design offers Soft (the
  default, measured after the shadcn/ui dashboard blocks), Classic (WordPress-like
  chrome), Paper (white with hairlines), Studio (monochrome after the Vercel dashboard
  and Geist: black buttons, the base colour as accent only) and the new **Console**
  (ops-console look: Geist Mono, caps with tracking, square corners, dashed group
  dividers, a crosshair on selected elements). Every theme has a light and a dark
  variant. Picking a theme card also sets its font, button radius and glow, and
  Console its palette; every field stays free to change afterwards
  (`theme.preset`, `theme.font` now also `geist-mono`).
- **One base colour.** Primary colour, tints, hover states and the shell derive from
  `theme.primaryColor` in OKLCH; "Erweitert" holds the accent, the sidebar colours and
  now the **danger, notice and success colours** (`theme.dangerColor`,
  `theme.warningColor`, `theme.successColor`, empty = default; success follows the base
  colour). Light base colours such as orange are no longer darkened on save: the
  stylesheet puts dark text on light primaries instead.
- **Density and fonts.** "Dichte" (`theme.density`: auto, compact, comfortable) scales
  spacing and control heights; the root font size follows the viewport. Fonts are local:
  System, Geist, Inter and Geist Mono from `admin/fonts/`, no external request.
- **Collapsible sidebar.** A button at the sidebar's foot shrinks it to its icons
  (tooltips from the labels, badges in the corner); the choice is remembered per browser.
- **Several bypass links for maintenance mode.** Settings → Access holds any number of
  URL parameter / key pairs (`access.maintenance.bypassLinks`), shows each finished link,
  and removes a link with its bin; hand different links to different people and revoke
  them one by one.
- Breadcrumb in the topbar instead of a repeated page title; settings forms as
  label-left tables on wide panels; a sticky save bar at the foot of every settings form;
  shared date formatting and empty-row rendering in the lists.

### Changed

- Admin, Copilot and image manager stylesheets read one token set
  (`css/nibbly-admin-tokens.css`): spacing, type scale, control heights, radii and the
  divider style (`--nb-divider-style`, dashed in Console). Custom selects (`css/nb-select.css`)
  take the same control height and type as the text inputs beside them; icon buttons,
  path fields and dropdowns in one row share one height.
- The AI settings, access, e-mail (SMTP in two columns), backup, system status, icon
  manager and forms pages were laid out on consistent grids: the enable switch sits level
  with its row, the four limit fields take equal columns, the budget bar and the image
  service select take half the width, the maintenance fields sit on a four-column grid.
- The contrast feedback under a colour field reports the contrast to the text the
  stylesheet will use (white or dark), not only to white.

### Fixed

- Direct loads of `#news` and `#events` no longer show empty lists (initial route deferred
  past the script's declarations).
- Status chips and highlighted cards lost their left accent line; the assistant card on
  the home tab gets its divider in visual order; hints sit 8px below their field in every
  layout; date and time fields in the event editor keep their full width.

## [2.3.2] — 2026-10-17

### Added

- **The AI agent also chats in the dashboard.** The "Assistant" card on the home tab now
  holds the same chat as the website (Agent | Classic switch, attachments, review cards)
  instead of a separate classic form; on every other tab it is the floating bubble as
  before. With "Offer the classic mode in the chat" off, the card shows the agent alone
  and says so; without the agent it shows the classic chat, labelled "Classic". The chat
  stays open as the panel when a card sends you to a settings page. The mode is remembered
  apart for the website and the dashboard (the dashboard's own actions, such as the theme
  or the language, are the classic assistant's). `NibblyAiCopilot.embed(host)` puts the chat
  into a container; `registerAgent()` no longer refuses the dashboard.
- **Animated feedback while the AI works:** a ring spinner in the image jobs panel while
  jobs are queued or running, a "thinking" bubble in the classic chat of the card, and a ring
  in the running indicator.
- **Image models of Kie.ai, fal.ai and Comet API reduced to five:** Nano Banana Pro,
  Nano Banana 2.1, GPT Image 2.5 Flare, GPT Image 2.5 Sunburst and Seedream 5.0 Pro
  (the live model lists still add what the services report). Parameters, ids and reference
  image support come from the providers' documentation and schemas (checked 2026-10-17;
  Comet's Seedream and a few parameters are marked unverified). The Kie.ai adapter is now driven
  by the registry (`includes/ai/image-services.json`) like the others; Comet routes by model
  (Gemini route for Nano Banana, OpenAI images route for GPT Image 2.5, an asynchronous route for
  Seedream). Prices by resolution of the Kie.ai models and of fal.ai's Nano Banana Pro are
  in the registry with source and date.
- Option "Offer the classic mode in the chat" (Settings → AI Settings → AI agent,
  `agent.classicMode`, on by default). Off, the chat shows the agent only, without
  the mode switch.
- Messages page: a notice appears when no enabled form stores messages locally
  ("Store locally" off everywhere) or when no form is enabled at all, with a button
  to the form settings.

### Changed

- The former Kie.ai image models lead to their successors: `gpt-image-2` becomes
  `gpt-image-2-5-flare`, `nano-banana-2` becomes `nano-banana-2-1`; Seedream is unchanged.
  Saved settings keep working through these aliases.
- fal.ai and Comet no longer list their earlier presets (Nano Banana 2, FLUX.1 schnell, Gemini 3.1
  Flash Image); any model ID can still be typed and is sent with the service's defaults.

### Fixed

- **The image tool no longer disappears** when an image service other than "Same as chat provider"
  is chosen and cannot work yet (no key, no model): the tab stays and says what is missing.

## [2.3.1] — 2026-10-17

### Added

- **An image service of its own, apart from the chat provider.** The AI settings
  have a new section "Images". The agent, the assistant and the image generator
  now take images from the service chosen there: "Same as chat provider" (as
  before), OpenAI, OpenRouter, Kie.ai, **fal.ai**, **Comet API** or a custom
  OpenAI-compatible service (base URL, model, size parameter, authentication,
  optional extra parameters). With Anthropic as chat provider, which has no
  image API, the agent can now create images through any of them. Each service has
  its own API key in `content/ai-secrets.json` (`imageServices.<id>.apiKey`); a
  service of OpenAI, OpenRouter or Kie.ai without an own key uses the chat key of the
  same provider, but only when that chat provider talks to the service's own host (a key for
  Groq, Mistral or a local gateway is never sent to api.openai.com). Keys are masked in the form and can be removed with the x button
  (`ai-clear-secret`, target `image:<id>`). Adapters (`includes/ai/image-services.php`)
  map one input (prompt, count, aspect ratio or size, scale, format, reference
  images) to each provider's parameters and run its queue or synchronous call; the
  registry `includes/ai/image-services.json` describes services and models and
  marks parameters that were not checked against the provider's documentation
  (`verified: false`, shown in the settings). Existing OpenAI, OpenRouter and Kie.ai
  paths, image jobs, the request ledger and resume are unchanged.
- **Prices per image model.** `pricing.imageModels` holds a price per image for a
  service and model (USD or EUR); the registry can carry one with source and date.
  Without either, the flat price per image (`pricing.imageCentsPerRequest`) applies as
  the default price; with that at 0 too the price is "not on file": the request counts
  against the daily limit but not the budget, and is tallied as
  `unpricedImageRequests`. Ledger entries record service, model and price basis.
- The agent can ask for the aspect ratios 4:3, 3:4 and 9:16 as well.
- **Reference images in the agent's `generate_image`.** The new parameter `references`
  (up to three) takes a `mediaRef` of the run or a `libraryPath` from `list_media`, to
  build on existing pictures (a style, a product photo). Wrong references fail the tool
  call at once; an image service without reference support (a fal.ai model without an
  edit endpoint, a custom service without it) says so. A picture made from references is
  labelled "AI-modified", one made from the description alone "AI-generated".
- **Prices by resolution and quality.** A price for an image model can carry tables by
  resolution step (`byResolution`: 1K, 2K, 4K) and by quality (`byQuality`: low, medium,
  high) next to the price for any image, in the settings (under the image price, "Prices
  by resolution and quality") and in the registry. A request uses the price of its
  resolution, then of its quality, then the price for any image, then the default price per
  image. Ledger entries record which one applied (`priceBasis.basis`), and the cost estimate
  of the image generator follows the chosen resolution and quality.
- **Live model lists for fal.ai and Comet API.** The model field suggests the models the
  service lists itself (`ai-image-models`, admin only): fal.ai's public model search
  (`text-to-image`, active) and Comet's public catalog (`model_type` image), fetched through
  the server, cached for a day in `content/ai-image-models-cache.json`, served stale when the
  service does not answer. Any model ID can still be typed.

### Changed

- Result images that a provider returns as an address are now downloaded through the
  hardened fetch used for web reading (public addresses only, pinned DNS, size and
  type limits, redirects checked; ports 80, 443, 8080 and 8443); only a provider that the administrator explicitly
  allowed as local is contacted directly. The agent's image tool is offered when the
  image service is usable (it needs a key), not when the chat provider has images.
- The copilot context reports `ai.imageUsable`; the assistant offers image drafts
  by the image service, not by the chat key.

- **Safety check for the AI agent, enforced on the server.** What a change set
  would do to the site is judged on the replayed content, not on the tool
  arguments or the model's words, in `includes/ai/agent/safety.php`: removing,
  hiding or emptying a large share of a page (or more than a handful of parts in
  one package, summed over all operations), losing most of a page's text,
  creating or touching many pages, news posts or events, and changing menus of
  many pages are stopped (`block`) or need an extra confirmation (`caution`). New
  text is checked for scams, phishing and requests for passwords or payment
  details, malware and script injection, hate, adult and illegal content, short
  or deceptive links, text aimed at an AI, and swapped contact or bank details.
  The check runs when an operation is staged, when the package is validated and
  again when it is applied, so a tampered change set cannot get through. `block`
  cannot be overridden, not even by administrators; `caution` is shown in the
  review and the package is applied only with a second confirmation
  (`confirmRisk`). The word lists cover the nine admin languages
  (`includes/ai/agent/safety-patterns.php`).
- **Harmful requests are refused before the model is asked** ("delete everything
  on the home page", building phishing or malware pages, hate): in the agent (start
  and replies) and in the assistant chat. The answer explains why and what to do
  instead (name a few specific sections). Refusals are audited by category only.
- **Nibbly map** (`includes/ai/nibbly-map.php`): one maintained table from a
  function or setting to dashboard area, settings tab, deep link and name in all
  admin languages. The assistants use it as knowledge and build links from it;
  a test fails when the dashboard has an area or tab the map does not know.
- **Concrete help when something cannot be done.** The dashboard assistant has a
  new tool `show_location`, and when it cannot or may not do a request (no tool
  calling, a value it could not use, an editor asking for an administrator
  setting, a switched-off module) the server adds a card with the exact place
  (for example Settings › Language › Admin Language) and a link that opens in a
  new tab. The agent has a new tool `find_in_dashboard` and shows the same cards
  with its answer.
- **Models, reasoning effort and costs in the AI settings.**
  - New installations start with provider-dependent models: OpenAI `gpt-6.1-sol`
    (chat/agent) and `gpt-6-luna` (text/SEO), Anthropic `claude-sonnet-5-5` and
    `claude-haiku-5-5`. Models and effort are remembered per provider and restored
    when the provider is switched back. A custom OpenAI-compatible endpoint gets no
    preset; OpenRouter and Kie.ai keep theirs.
  - "Reasoning effort" per purpose (chat/agent and text/SEO): Recommended, Low,
    Medium, High. "Recommended" is medium for chat/agent and low for text/SEO and is
    always sent explicitly. A central registry (`includes/ai/model-capabilities.json`)
    decides per provider, host and model whether a level exists and which API
    parameter carries it (`reasoning.effort` on the Responses API, `reasoning_effort`
    on Chat Completions, `output_config.effort` at Anthropic). Unknown models and
    custom endpoints get no parameter, and the settings say so. Unsupported levels
    are reset to "Recommended" and refused on the server. `temperature` is left out
    for the models that reject it. Thinking counts toward the output limit, so calls
    with an effort level get extra output headroom (inside the configured limit).
  - Costs per provider and model instead of one global pair: a central price catalog
    (`includes/ai/pricing-catalog.json`, USD per 1M tokens, source and date) with
    context tiers (OpenAI above 272,000 input tokens, Haiku 5.5 above 100,000), cache
    reads and writes, own prices in USD or EUR for other models and an exchange rate
    shown with its date (own rate possible). A model without a price is shown as
    "Price not on file", is never counted as 0 or with another model's price, and is
    tallied apart in the usage figures. Costs are kept with four decimals of a cent and
    rounded only for display. Cached and reasoning tokens are read from the provider
    reports without double counting; there is no surcharge for the effort level.
    Every settled request stores the model and the price basis it was priced with.
  - After saving a powerful chat model (Sol, Sonnet) the dashboard asks once whether
    the AI agent should be enabled.
- API keys: a saved key is shown by a masked placeholder and a hint, never as a value;
  a small x with confirmation removes it at once (`ai-clear-secret`). The same for the
  Brave Search and Pexels keys. Mask characters are never saved as a key.

### Changed

- The AI settings: the "Models" section has two columns (Chat/Agent, Text/SEO); the
  organization field is labelled "OpenAI organization ID (optional)" with an explanation
  and only shown for providers that take it; the checkboxes for removing keys are gone.
- The collapsible groups of the AI agent settings look like the other cards of the
  dashboard (smaller summary, +/- marker, separator).
- Existing installations keep their models and credentials. They keep their request
  behavior (no reasoning parameter is sent until a level is chosen), and the former
  global input/output price pair carries over as marked estimates for the models in use.
  See UPGRADING.md.

- **Safety check for the classic Copilot.** Suggestions (including translations) and
  content drafts are judged with the same word lists as the agent: harmful text
  (scams, phishing, malware, hate, adult or illegal content) is dropped before
  anything is signed or shown, and the person is told why; odd text (asks for
  passwords or payment details, short or deceptive links, text aimed at an AI)
  stays signed but carries a visible safety note. Dropped suggestions are audited
  by category only.

### Fixed

- The agent question after saving AI settings is now asked whenever a key is newly
  added for a powerful model, not only once per model in the browser.
- The assistant button in the dashboard appears right after the first key is saved
  (script and styles are loaded then) and disappears when the key is removed,
  instead of needing a page reload.
- Dropdown lists (`nb-select`, also the mobile settings navigation and the editor)
  squeezed their rows and cut off the text when the list was higher than the room
  below the field; the rows now keep their height and the list scrolls. A browser
  check (`tests/dropdown-check.js`) opens every dropdown of the dashboard at four
  window sizes and fails on squeezed rows.
- Form field cards in Settings → Forms: the delete button sits in the corner of its
  card level with the first input, and the "Required" checkbox is a normal box at the
  left of its cell instead of a stretched, centered control.

- "Set the system language to English/German" failed when the model sent a
  language name ("English", "Englisch", "Français") instead of the code, and the
  assistant then only pointed at "the settings". Language names in any admin
  language, theme words ("dunkel", "hell", "auto") and the synonyms "system
  language" and "interface language" are understood now.

## [2.3.0] — 2026-10-16

### Added

- **AI agent in the frontend Copilot (off by default).** An optional agent mode
  works through a request with a fixed set of tools, stages all changes in a
  change set and presents it for review: before/after per operation, images with
  credit and AI badge, sources with warnings, validation and render checks, a
  preview on the real page (owner only), one confirmed click to apply, a backup
  and journal for every apply, undo, and a separate publish step. New news,
  events and pages stay hidden or private until published. A chat "yes" never
  applies anything.
- Page structure is learned from the rendered template (capability map with a
  cache) plus optional restricting overrides in `content/ai-schema/`.
- Web research with the Brave Search API: the server fetches pages through the
  new safe fetch, extracts text, and a separate tool-less model call returns
  only validated facts; the agent never sees a web page. Sources are kept with
  the change set and can be appended to news posts.
- `read_url`: the agent can read web addresses the user writes in the chat, with
  or without a Brave key. Only addresses typed by the user are accepted; the
  same protections as for search results apply.
- Images for the agent: upload by button or drag and drop (re-encoded without
  metadata), Pexels stock photos with credit, generated images with the AI
  label. Images stay in a staging folder until a package that uses them is
  applied.
- Settings in Dashboard → Settings → AI Settings → "AI agent": enable, limits
  per run, what editors may do, Brave and Pexels keys (masked, kept when empty),
  media folder, credits and sources in news, clearing the scan cache. System
  status shows what the agent needs (tool calling, GD and WebP, keys, storage).
- Texts for the agent and the search engine check in all nine admin languages.
- **Search engine check in the system status.** A staging site is blocked for
  search engines on purpose, and the block is easily forgotten at go-live. The
  new panel "Search engines" next to the security check reads what the live
  site really delivers (`robots.txt`, robots meta tag and `X-Robots-Tag` of the
  home page, `sitemap.xml`) and compares it with the SEO settings: a site-wide
  block, a static `robots.txt` that replaces Nibbly's, a sitemap or `siteUrl`
  on another domain or `http://`, a redirect to another domain, maintenance
  mode and single `noindex` pages. A banner on the dashboard shows whenever the
  live site blocks search engines, with a one-click "Allow indexing" and the
  fix for each finding. Nothing is changed automatically; local hosts are not
  checked. The check runs in the background at most once an hour while
  something is wrong (12 hours when clean) and on demand.
- Settings → Branding → Search engines: switches for the site-wide block
  (`seo.noindexSite`) and the public website address (`seo.siteUrl`), which had
  no interface before.
- Dashboard assistant: it can offer actions as cards the person confirms: open a
  dashboard area or settings tab, and (administrators only) change the dashboard
  theme (light/dark/system), main and accent colour and language. The model only
  picks from a fixed catalogue (`includes/ai/dashboard-actions.php`), the server
  validates every call against the role, and the dashboard performs the change
  through its own settings form, so contrast rules, rights and CSRF still apply.
  Needs a provider with tool calling; the frontend assistant is unchanged.
- Agent: administrators can have a page shown in a menu or taken out again
  (`stage_set_page_menus`, e.g. "put the page into the main menu"). Only
  administrators get the tool; the menus must exist in `content/menus.json`, and the
  review lists what changes. A private page in the main menu is flagged.

### Changed

- `seo.noindexSite` now blocks the whole site consistently: besides `Disallow: /`
  in `robots.txt` it sets `noindex, nofollow` in the robots meta tag and in an
  `X-Robots-Tag` header, empties `sitemap.xml` and leaves the sitemap line out of
  `robots.txt`. Before, only `robots.txt` was affected and the pages stayed
  indexable.
- The HTTP requests of the security check identify as a bot (`Nibbly-Security-Check-Bot`),
  so the statistics do not count them as visitors.
- AI limits are guides: a request that starts below a daily request limit or the
  monthly budget may finish, and a request of an agent run already under way
  (second turn on, reading steps) may use up to 10 % more before it is stopped.
  The first request of a run, standalone requests and the image limit stay strict.
- Warning bars appear in the dashboard, the AI settings and the assistant when a
  limit (monthly budget, daily requests, text or image requests) is 80 % used or
  reached; admins can raise the limits from there.
- Default limits fit the agent: 64,000 input tokens, 16,000 output tokens, 300
  requests and 250 text requests per day. Saved values are not changed.
- Newer OpenAI models: `max_completion_tokens` is sent to api.openai.com, and a
  provider that rejects `max_tokens`, `max_completion_tokens` or a custom
  `temperature` is asked again without it; the finding is remembered per model.
- Agent review: changes are named in plain words ("Change “Text” in section 14
  (text): “Loslegen”" instead of `sections.14.content`), in the admin language. The
  apply dialog and the note after applying say that changes to existing content are
  live at once and only new pages, posts, events, sections and list items stay
  hidden until published (it used to say this for every package).
- Agent review: a package of up to four changes shows its before and after right
  away; block ends in rich text no longer run together.
- Agent preview: it opens in the same tab and the bar (in the admin language) has two
  plain links back to the page the assistant was on: "Back to the assistant" and
  "Apply changes", which opens the assistant's own confirmation there. The preview
  itself writes nothing and needs no second tab or cross-tab message.
- Agent: the summary of a prepared package is also written into the chat, so the
  history keeps it. A new tab finds the open package again (only the run id is kept
  in local storage, for 48 hours).
- The agent talks to api.openai.com through the Responses API (`/v1/responses`):
  reasoning models cannot combine function tools with reasoning on
  chat/completions. Reasoning comes back encrypted (`store: false`, nothing is kept
  at OpenAI) and goes into the next turn. Other OpenAI-compatible hosts keep
  chat/completions and switch to `/responses` for a model whose request was refused
  with "use /v1/responses" (remembered per model).
- The AI settings load the provider's model list for the model fields, keep the
  save bar in view and fold the rarely used agent groups; long model lists in
  the dropdown stay readable.
- AI API keys (provider, Brave Search, Pexels) are stored in `content/ai-secrets.json`
  (owner-only, in `.gitignore`) instead of `content/ai-settings.json`, which can now be
  committed. Keys that an older version left in the settings file move there when an
  administrator next opens the dashboard or the AI settings. Code reads the settings
  through `nibblyAiReadStoredSettings()` (`includes/ai/settings-store.php`).
- `nibblyNetSafeFetch()` (`includes/ai/agent/net.php`) is now the only way to
  fetch external URLs: public addresses only (IPv4 and IPv6 ranges), DNS pinning,
  manual redirects, size and content-type limits. Existing external fetches use it.
- Generated images get the AI label automatically; events are written through
  the locked JSON update; a file-based rate limiter backs the agent's limits.
- Saving the AI settings keeps the `agent`, `research` and `stock` sections when
  a caller does not send them.

### Fixed

- Dashboard → Pages: language versions with translated URL paths (de `leistungen`,
  en `services`, linked in `$PAGE_MAPPING`) are one page again, aliases of one page
  give one row, and "Edit" instead of "Create" is shown. Every language version
  carries its own slug (`languages[lang].slug`), used by editor links, the view link,
  the language select, duplicate, trash and the SEO badges. A missing translation is
  created under the path the mapping intends (`targetSlugs`). Pages with identical
  slugs are grouped as before; existing files and public URLs are untouched.
- Saving settings: an old invalid path (favicon, logos, Open Graph image, site URL)
  no longer blocks saving other settings such as colours and radius; only changed
  values are validated.
- Agent: a run that finished while the page was closed or the session had expired
  (answer or prepared package) shows its answer in the chat again after the next
  login or reload, and the chat history saves it.
- Agent: the heading that opens a new page is its title (h1), not an h2.
- The "Skip to main content" link of the website and of the dashboard is moved fully
  off screen until it is focused; before, its shadow showed at the top left.

### Security

- External links written by the agent must come from the user or from a fetched
  source of the run, also inside rich text.
- The status of a foreign change set is no longer revealed by `ai-agent-revalidate`.
- Added `agent-security-smoke.py`, `agent-api-smoke.js` and a Chromium check
  (`tests/agent-browser-check.py`, also run in CI).

## [2.2.0] — 2026-09-27

### Security

- Reject uploads whose filename extension is not an allowed media type in
  `upload-image` and `upload-audio`, matching `upload-media`. These handlers
  previously trusted only the detected MIME type, which a polyglot file can
  forge (valid image/audio magic bytes plus a `.php` name), letting an
  authenticated editor write an executable script into the web-served
  `assets/` tree. As defense in depth, `assets/.htaccess` and `router.php`
  now refuse to execute code files (`.php`, `.phtml`, `.phar`, `.cgi`, …) from
  `assets/`.
- Sanitise uploaded SVG files (`includes/svg-sanitizer.php`): remove
  `<script>`, `<foreignObject>`, event-handler attributes, `javascript:` links
  and `url(javascript:/data:)` in paint/style, and refuse SVGs with an inline
  DTD (XXE and entity expansion). SVG is served inline as `image/svg+xml`, so
  an unsanitised upload was a stored cross-site-scripting vector.
- Block dangerous URL schemes (`javascript:`, `data:`, `vbscript:`, …) in link
  targets stored in page content. `editableLink()` and the gallery and event
  card renderers output the target through a shared allowlist
  (`nibblySanitizeHref()`); safe `http(s)`, `mailto`, `tel`, relative and
  anchor links are unchanged.
- Strip control characters from the SMTP `EHLO` hostname so a spoofed
  `SERVER_NAME` cannot inject SMTP commands.
- Extend `tests/security-smoke.php` with SVG-sanitiser and link-allowlist
  checks, and add `tests/upload-security-smoke.py` (polyglot rejection, SVG
  sanitisation, real media still accepted).

### Removed

- Remove the point-in-time audit records `SYSTEM-REVIEW.md` and
  `SYSTEM-IMPLEMENTATION.md` and the redundant `llms-full.txt` (its content is
  covered by `architecture.md` and `AI-AGENT-GUIDE.md`; `llms.txt` remains).
  Updated the backup manifest and `llms.txt` accordingly.

### Added

- Add a security self-check to System status (administrators only). It creates
  short-lived probe files with random names in `content/` (`.json`), `backups/`
  (`.zip`) and the existing media trash folders, requests them through the
  site's own public address and evaluates only the HTTP status. A public control
  file must be reachable, so a wrong address cannot pass as protected. An nginx
  proxy that serves static files itself (HestiaCP, or Plesk with "Serve static
  files directly by nginx") bypasses `.htaccess` and exposes password hashes,
  the SMTP password, form submissions and backups. A public `content/` or
  `backups/` shows a warning banner on every dashboard page; public media trash
  folders alone are a warning in System status. Both list the affected folders
  with ready-to-copy nginx rules and HestiaCP/Plesk instructions. The check runs in
  the background after the dashboard loads (short timeouts, session lock
  released, probe files always removed), caches its result per address in
  `content/security-check.json` (12 hours when protected, 10 minutes when
  exposed) and can be repeated with "Check now". Local and intranet addresses
  are checked only on request; the PHP development server is skipped.
- Report further security findings in the same panel: dashboard use without
  HTTPS, plain `http://` without a redirect to `https://`, HTTPS not recognised
  by PHP (session cookie without `Secure`), a reverse proxy that hides visitor
  IP addresses (login lockout, form rate limits and statistics then treat all
  visitors as one), `display_errors` on public pages, the development login on
  public servers and PHP branches without upstream security support. The
  System status menu item shows the number of warnings.
- Add `tests/security-check-smoke.py`: an nginx-like static-file proxy in front
  of the development server or, with `--apache`, Apache/PHP-FPM with the real
  `.htaccess` (also run in the Apache CI job), with and without the documented
  rules, plus self-signed TLS, HTTP redirect, cleanup, cache and API checks.

### Changed

- Documented nginx rules for Nibbly's internal folders (`content/`, `backups/`,
  `cli/`, `tests/` and the media trash folders) for HestiaCP
  (`/home/<user>/conf/web/<domain>/nginx.ssl.conf_nibbly`, then
  `nginx -t && systemctl reload nginx`) and Plesk ("Additional nginx
  directives", or keep "Serve static files directly by nginx" switched off) in
  `ROUTING.md`, `README.md`, `architecture.md`, `AI-AGENT-GUIDE.md` and
  `deploy.example.sh`.

### Fixed

- Refuse web requests to `cli/backup.php`, `cli/convert.php` and `cli/make.php`
  with HTTP 404 where a server ignores `.htaccess`; cron jobs and shell use are
  unchanged.
- Keep the dashboard's security warning banners readable on narrow screens: the
  title wraps and the call to action moves below the text.
- Keep site icons visible in the frontend admin bar. The bar turned every logo
  at `assets/images/favicon.svg` white to suit the default Nibbly icon, which
  erased multi-coloured site icons stored at that path. It now decides from the
  rendered pixels: only dark single-colour logos are shown in white.

## [2.1.0] — 2026-09-26

Compatible features and fixes. The documented API, storage formats, accounts
and sessions are unchanged.

### Added

- Add AI disclosure labels for images (EU AI Act, Art. 50). Editors mark files
  as AI-generated or AI-modified in the media library; uploads with embedded
  provenance (IPTC digital source type in XMP or C2PA) are labelled
  automatically. Labels are stored in `content/media-meta.json`, follow renames,
  moves, trash and restore, and are rendered automatically by editable images
  and the image, card, news and event output: a small "AI generated"/"AI
  modified" label in the bottom-right corner with a localized alt text, hidden at
  rest and shown after lingering (600 ms hover, or `js/ai-labels.js` on touch
  devices). German pages can show the German "KI" artwork instead (Settings →
  Language → AI labels on images). In the visual
  editor, labels follow image swaps, undo/redo and label changes in the media
  library right away (`window.NB_AI_LABELS`).
- Add `contentBindAttrs()` for elements that repeat a field shown elsewhere,
  such as image previews or table-of-contents labels. The visual editor now
  refreshes these bound elements live after group edits, undo and redo.
- Show saved backups for the current page in the frontend admin bar, in both
  browse and visual-edit modes. The compact dropdown uses local dates, refreshes
  after saving, and provides a confirmed Apply action with an unsaved-change
  warning. Remove the reorder instruction from the toolbar hint.
- Guard frontend backup restores against concurrent page changes, require a
  successful safety backup, and keep the previous editing mode after reloading.
  Backup lists now exclude similarly named and nested pages. Add isolated API
  and toolbar regression coverage for restoration, image references and errors.
- Offer to activate the visual editor after two clicks on non-interactive page
  content while logged in. The accessible dialog appears once per page load;
  links, controls, existing dialogs and active editing are excluded.

### Changed

- Added a routing regression suite for both the PHP development server and
  an isolated Apache/PHP-FPM server using the actual `.htaccess`, with a separate
  Apache CI job. Checks cover login settings, relative CSS, POST redirects,
  future directory collisions, public pages, API access and protected paths.
- Documented routing collision prevention and required Apache/post-upload
  verification in `ROUTING.md`, linked from agent, contributor, upgrade and
  release guidance. PHP development-server tests are not Apache coverage.
- Added PHP 8.1 to the CI compatibility matrix alongside PHP 8.4/8.5.
- Clarified that PHP 8.1 is the minimum requirement, while PHP 8.4/8.5 are
  recommended for production. PHP 8.1 compatibility testing does not extend its
  expired upstream security support.

### Fixed

- Keep SEO fields without a form control, such as the sitemap `priority` read
  by the sitemap or site-specific keys, when saving a page in the content
  editor; the dashboard and the save API previously dropped them.
- Align the image manager with the dashboard typography when it opens over a
  website: base size and line height, centred radio buttons and spacing in the
  replace dialog.
- Show replaced images immediately in the media library and lightbox: previews
  carry the file's modification time, so the browser no longer shows the
  cached old file.
- Use the editor font for toast notifications on websites.
- Show the link icon in link fields of the group editor instead of `undefined`.
- Keep editable images filling their frame in edit mode. The editor's image
  wrapper is styled in CSS instead of inline styles, so sites can adapt it, and
  follows images that fill a container via `object-fit` or absolute positioning.
- Use the admin font in the image manager, its dialogs and the leave-edit-mode
  confirmation when they open over a website; site heading styles no longer
  apply to their titles.
- Open the linked settings tab when the dashboard loads on a settings address
  (`#settings/…`, also after saving the language). A script error previously
  left the panel unloaded and blocked back/forward navigation in the dashboard.
- Separate backup restore controls from visual/content editor actions with
  more spacing and a divider; use a separate row on narrow screens.
- Load shared content referenced by standalone editable text, links, images,
  icons and lists before opening the visual editor, including its save revision.
  This prevents missing-revision conflicts and preserves unrelated shared fields.
- Update list visibility button labels and tooltips immediately after hiding or
  showing an item.
- Preserve the current page when the content editor refreshes its page list.
  Opening the editor from the frontend now keeps the requested page, heading,
  and desktop/mobile selectors in sync instead of resetting to the first entry.
  The admin-bar content and SEO links share the encoded page destination using
  the explicit `dashboard.php` endpoint. Regression tests cover query/hash links,
  multiple languages, and both page-list/content response orders.
- Use a shared caret inset for admin/editor dropdowns, including native-picker
  controls. Reserve text space, support RTL and retain system arrows in
  forced-colors mode.
- Canonicalize PHP directory entry points and file/directory name collisions
  before generic routing. `/admin` gets its directory slash, `/login` and
  `/login/` reach the styled login form, and PHP endpoints with an extra slash
  reach their real handler. HTTP 308 preserves query strings and POST bodies.
- Share local canonicalization between the development router and front
  controller; directory handlers receive their actual PHP script identity.
- Send admin logins directly to `dashboard.php` so Apache cannot redirect them
  into the dashboard fragment directory and return 404. Both clean dashboard
  URL variants redirect to `dashboard.php`, preserving query parameters and
  relative asset paths in Apache and the local PHP router.

### Upgrade notes

- Deploy the complete core, including the new `includes/ai-labels.php`,
  `includes/routing-helper.php`, `css/ai-labels/` and `js/ai-labels.js`.
- Merge the updated `.htaccess` with site-specific rules, upload it with the core
  and run the Apache and post-upload checks in
  [ROUTING.md](ROUTING.md#required-verification).
- Sites with a customized `includes/footer.php` add the `js/ai-labels.js` loader
  and `window.NB_AI_LABELS` from the 2.1.0 footer. Without them, labels still
  render, but touch devices cannot reveal them and the visual editor updates
  them only after a reload.
- AI labels are stored in the site-owned `content/media-meta.json`; keep it with
  `content/` in backups and deployments. Existing images stay unlabelled until
  an editor marks them in the media library.
- Follow the [upgrade guide](UPGRADING.md#upgrading-from-200-to-210) for the
  complete steps and post-upgrade checks.

## [2.0.0] — 2026-09-06

### Breaking changes

- The documented admin API actions `save` and `save-settings` now require the
  `revision` returned by the corresponding load request. Missing revisions return
  HTTP 428; stale revisions return HTTP 409. Existing custom integrations must be
  updated. This API incompatibility is the reason for the major version bump.
- Update the PHP API and editor assets together. Sites with a customized footer
  must integrate the shared revision client before using the inline editor.
  See [Upgrading to 2.0.0](UPGRADING.md) for deployment and recovery steps.

### Added

- Added revision checks for page/settings saves with HTTP 409 conflicts, HTTP 428
  for missing revisions, and a shared comparison/download/reload dialog in the
  dashboard and inline editor. Direct API integrations must send the revision
  returned by their load request.
- Added persistent AI budget reservations, idempotent usage settlement, Kie task
  recovery and administrative reconciliation of uncertain provider outcomes.
  Model presets, image aliases and capabilities share one server-owned catalog.
- Added a System status view for PHP extensions, writable storage, the latest
  successful backup, failed image jobs and open AI reservations.
- Added GitHub Actions for PHP 8.4/8.5 and Chromium, with reproducible browser
  tooling and integration coverage for setup, SMTP, media lifecycle, revisions,
  fallback generation, budget concurrency, Kie recovery and analytics migration.
  All three successful checks are required by the existing main branch ruleset.
- Added an analytics privacy toggle and distinct disabled, empty and failed states.
- Added an offline system test runner and regression suites for authentication,
  HTTP endpoints, concurrent storage, password resets and mocked AI providers;
  an optional Chromium check covers dashboard navigation and mobile editing.
- Added the public MingCute Core Regular/Core Filled collection to the Iconify
  import dialog under Apache License 2.0, with source and license metadata
  retained for imported icons.

### Changed

- Split the stable admin API into twelve domain handlers and extracted dashboard
  scripts by workflow, keeping public URLs and the no-build PHP installation.
- Partitioned analytics into daily records and compact monthly archives. Existing
  totals migrate automatically; historical summaries are cached and current-day
  writes invalidate the cache. Legacy migration data is retained without hashes.
- Routed SEO diagnostics, conflict/status messages and previously mixed-language
  editor controls through the shared translation layer; SEO length checks count
  Unicode characters. New detailed copy is available in German and English,
  with the established English fallback for other dashboard languages.

### Fixed

- Fixed dashboard field and action wrapping across phone/tablet/desktop sizes,
  including backup retention, remote targets, settings, forms, image uploads,
  color controls, menu ordering and page filters. Narrow settings views use a
  compact selector.
  Hidden native select fields no longer expand the page invisibly.
- Partial settings updates preserve unrelated privacy, module, maintenance and
  login values. Editor settings responses omit SMTP, backup and AI credentials.
- Automatic fallback fields merge transactionally without overwriting edits.
  Page backups have collision-resistant names understood by list/restore actions.
- Ambiguous AI POST failures no longer trigger automatic duplicate submissions.
  Explicitly enabled local image providers may download from their configured
  origin only; redirects cannot escape the download URL validation.
- Added setup CSRF validation and atomic backup configuration updates.
- Unified dashboard, API and inline-editor session validation. Deleted accounts,
  changed passwords and changed roles now take effect on existing sessions;
  login rotates the session ID, local dev sessions remain loopback-only, and
  login/logout destinations reject unsafe URLs.
- Password-protected pages revoke previous unlocks when their password changes.
- News IDs can no longer escape the news directory. Renaming a post preserves
  the original if saving fails, and duplicate slugs cannot overwrite other posts.
- Replaced regex-only rich-text filtering with an HTML allowlist that rejects
  encoded script URLs and active markup. Existing news content is filtered when
  rendered, and inline-editor branding is escaped in HTML attributes.
- Added atomic JSON transactions for form tokens, submission limits, inbox
  writes, user changes, AI usage/history and analytics. Concurrent requests no
  longer lose records; damaged stores are preserved instead of overwritten.
- Password-reset tokens are consumed atomically, password changes revoke reset
  links, and concurrent role changes cannot remove the last administrator.
- Forms now return a failure when neither email delivery nor local storage
  succeeds, and distinguish failed delivery from intentionally local storage.
- Kie chat uses the correct provider adapter for streaming callers without an
  extra failed request. GPT requests include the configured output-token cap.
- Kie image requests derive the aspect ratio from explicit image dimensions.
  AI chat, image prompts and image history preserve UTF-8 at length limits;
  malformed request JSON fails before reaching a provider.
- Only one worker can claim a queued image-generation job, preventing duplicate
  provider requests from concurrent workers.
- Restores now include supported video, audio and document formats, stage and
  validate archive data before replacing content, reject symbolic-link targets,
  and roll back ordinary write failures. Full restores abort if the safety
  backup fails. Process termination or machine failure still requires recovery
  from a backup.
- Backups created in the same second no longer overwrite each other. Backup
  locks keep a stable inode; runtime locks and restore staging files are excluded.
- Development and production routing share the same front controller. Language
  homepages and nested URLs retain working asset paths with trailing slashes;
  trash, hidden files, tests and CLI paths are blocked from HTTP access.
- CLI tools also work in deployed copies without the development router.
  Empty byte-range requests now return HTTP 416.
- Mobile editor layout and notifications follow the actual admin-bar height;
  informational notifications have a readable background and fit the viewport.
  Analytics charts use distinct integer ticks when traffic is low or zero.
- Removed obsolete PHP resource cleanup calls that produce PHP 8.5 deprecation
  output. Corrected the documented minimum PHP version to 8.1 and updated stale
  tests to the current theme and shared session validation.
- Hide the Dashboard AI section and setup notice when the AI module is disabled,
  including for administrators.
- Full-site backups now omit local QA evidence, development tests and examples,
  agent instructions, and other internal root documentation. Product, version,
  and third-party license documents remain included.
- Restoring older full-site backups no longer fails when they contain PHP smoke
  tests: legacy `tests/` entries are accepted for compatibility but skipped
  during extraction.

### Upgrade notes

- Existing administrator sessions require one fresh sign-in after this update
  so they can be bound to the current account password. No account migration
  or password change is required.
- PHP 8.1 is the actual language minimum; PHP 8.4/8.5 are the tested production
  targets. The former PHP 7.4 claim was incorrect.
- Analytics storage migrates automatically on first access. Keep a full pre-upgrade
  backup for rollback; do not downgrade only the PHP files after migration.
- Rich text uses an HTML allowlist. Check custom content and move unsupported
  layout markup into site-owned templates.
- Follow the complete [upgrade guide](UPGRADING.md), including custom footer
  integration, provider job handling and post-upgrade checks.

## [1.6.1] — 2026-08-05

### Added

- Kie.ai as a configurable AI provider with independently stored credentials.
- Kie chat/text model presets for GPT-5.6, Claude Sonnet 5 and Gemini 3.5 Flash.
- Kie image generation support for GPT Image 2, Nano Banana 2 and Seedream 5.0 Pro, including asynchronous job polling and reference images.

## [1.6.0] — 2026-08-04

### Added
- Added first-class nested page paths for SEO-preserving site structures such
  as `/produkte/rokivit-d3` and `/studien-vitamin-d/vitamin-d`. Nested pages
  work in the Apache and development routers, custom templates, the Dashboard,
  automatic navigation, language switching, Canonical and hreflang metadata,
  link pickers, sitemap generation, access protection, backups, and AI tools.
- Added nested-path support to `cli/make.php` and `cli/convert.php`. Public path
  segments are stored in flat JSON filenames with a reserved `__` separator;
  for example `de_produkte__rokivit-d3.json` serves
  `/produkte/rokivit-d3` when German is the default language.
- Added a nested-page-path smoke test covering validation, path/key conversion,
  navigation auto-discovery, sitemap URLs, and CLI scaffolding.

### Changed
- Page JSON generated by the Dashboard and CLI tools now records its public
  `path` explicitly while retaining the existing `page` content identifier for
  backward compatibility with flat pages.

### Fixed
- Canonical sitemap entries for clean page routes now consistently use a
  trailing slash, avoiding a redirect between the sitemap URL and the page's
  canonical URL.
- Media Library thumbnails and image previews now show the checkerboard only
  when the rendered image contains transparent pixels; opaque images use the
  same neutral background as document previews.
- Media Library folder counters are calculated after the current file list has
  loaded, so the sidebar no longer reports zero for populated folders.
- Saving branding settings no longer drops the configured SEO site URL,
  organization name, or site-wide robots setting.
- The PHP development router now serves video and audio byte ranges correctly,
  allowing scroll-scrubbed and seeked media to work on localhost.

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
- Replaced the Dashboard's wrapping status pills with a responsive segmented
  status strip. Labels and values now use a clearer hierarchy, recent edits get
  additional width, and long titles truncate cleanly with their full value
  available as a tooltip.
- Clarified the mutually exclusive backup restore modes with an explicit choice
  prompt, visible radio/check indicators, a stronger selected state, and a
  single-column mobile layout.
- Centralized release metadata in the root `VERSION` file. Runtime displays now
  read through `includes/version.php`, and setup/config templates no longer
  define their own release numbers.
- Cleaned up Markdown documentation: removed duplicate local front-end agent
  rules from `.claude/CLAUDE.md` and clarified which root documents are
  canonical.
- Refined the Dashboard settings layout for list pagination and editor button
  styling with grouped fieldsets and a three-column pagination settings row.
- Added stronger agent and converter documentation for existing-site
  migrations: preserve the original visual system, keep CSS/JS/assets/class
  hooks intact, and verify converted pages section by section with screenshots
  or computed-style comparisons.

### Fixed
- Backup restore now accepts production archives that omit the development-only
  `router.php`, allows documented site template locations, and restores all
  site-owned templates, styles, and navigation files in content-only mode.
- Corrected the core version metadata so the Dashboard and login screen report
  1.5.4 instead of the stale 1.5.2 value.
- Dashboard editor section navigation now lists top-level field groups on
  custom-layout pages that do not use a `sections[]` array.
- Visual Editor sessions now use sliding activity refreshes and a CSRF-protected
  keepalive request so long editing sessions stay authenticated while editors
  interact with fields before saving.
- Visual Editor repeatable lists can now add scalar string defaults without
  converting them into character-indexed objects.
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
