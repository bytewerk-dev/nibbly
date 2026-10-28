# Upgrading Nibbly

Read every section between the installed version and the target version, oldest
first. Installations before 2.0.0 start with [Upgrading to 2.0.0](#upgrading-to-200).

## Upgrading from 2.5.1 to 2.6.0

Version 2.6.0 adds the editorial guide at `/admin/handbook` and makes Pop the default theme of
new installations. The Icon Manager gets an icon style and a stroke width (global and per icon);
existing icons are detected and shown as before. No manual step is needed; existing sites keep their look.

1. Deploy the complete core. New: `admin/handbook.php`, `includes/handbook.php`,
   `includes/handbook/` (chapters, styles, screenshots), `includes/theme-defaults.php`. Changed:
   the dashboard, the login page, the settings API, `admin/setup.php`, `includes/footer.php`,
   `includes/backup-helper.php`, `js/inline-editor.js`, `css/inline-editor.css`, `admin/style.css`
   and `admin/lang/*.json`. A site with its own `includes/footer.php` gets the admin-bar link to the
   guide only once its footer is updated from the core version.
2. Theme defaults. A saved `theme` with a `preset` key keeps everything it stores. A saved `theme`
   without `preset` (every installation set up before this release that never saved Settings →
   Design) keeps Soft and `#3858e9`; the first save under Settings writes `preset: "soft"`
   explicitly. Only installations without any saved theme now start with Pop.
3. The guide is on by default. To hide it, switch off Settings → Modules → Handbuch
   (`modules.handbook: false`). A site that had its own guide at `/admin/handbuch` (for example a
   site-specific `admin/handbuch.php`) can move its site-specific chapters to
   `includes/site-handbook/` (see `AI-AGENT-GUIDE.md`, "Editorial Guide") and drop the old files.
4. Developer tooling: `tests/handbook-screenshots.py` needs Playwright
   (`cd tests && npm install && npx playwright install chromium`).

## Upgrading from 2.5.0 to 2.5.1

Version 2.5.1 is a compatible release: the settings get their own second sidebar with a search
field, the total reset moves to the end of the backup page, and several settings layouts are
tidied. No manual step is needed.

1. Deploy the complete core. Changed: the dashboard (`admin/dashboard.php`,
   `admin/dashboard/scripts/*`, `admin/style.css`), `admin/setup.php`, `includes/ai/nibbly-map.php`
   and `admin/lang/*.json`.
2. The total reset is no longer a settings section; it is the folded section at the end of the
   backup page. Instructions for editors that point to "Settings → Danger zone" need updating.

## Upgrading from 2.4.3 to 2.5.0

Version 2.5.0 adds the People module with Person and Team blocks, a Menus & Footer editor, own
pages for events and people, trash for news and people, website languages in the dashboard, a
WordPress-style writing view for pages, news, events and people with a highlighted HTML code view,
and one language switch in every editor. Two steps need attention: contact pages (step 3) and the
new content folders (step 4).

1. Deploy the complete core. New files: `admin/api/people.php`,
   `admin/dashboard/scripts/{languages,menus,page-writing,people}.php`,
   `includes/{people,person-page,event-page,public-strings,site-languages,menu-store,menu-adapters,block-icons}.php`,
   `includes/block-renderers/{person,team}.php` and the starter images in
   `assets/images/starter/`. Changed: the dashboard, `admin/style.css`, `admin/api/*.php`,
   `includes/content-loader.php`, `includes/header.php`, `includes/footer.php`, `route.php`,
   `includes/seo-helper.php`, `includes/default-iconset.json`, `css/*.css`, `js/*.js` and
   `admin/lang/*.json`.
2. Removed files: `includes/contact-form.php`, `includes/pricing-contact-form.php`,
   `api/contact.php` and `api/smtp-config.example.php`. Delete them on the server if your
   deployment does not remove files. A leftover `smtp-config.php` stays blocked by `.htaccess`
   and the dev router.
3. Contact pages. Every public form is now a JSON form, and a page no longer gets a form because
   of its slug (`contact`, `kontakt`, `contacto`). Add `"form": "contact"` (or the id of your form
   in `content/forms/`) to the JSON of each page that should show a form below its sections.
   Custom templates that included `includes/contact-form.php` must render a JSON form instead.
4. New content stores appear on first use and must be writable by the web server and included in
   backups like the rest of `content/`: `content/people.json` (People module),
   `content/menus.json` (Menus & Footer, written on the first save) and `content/news-trash/`
   (deleted news posts). Deleting a news post now moves it there; the API action `delete-news`
   is replaced by `trash-news`, `list-news-trash`, `restore-news`, `delete-news-permanent` and
   `empty-news-trash`.
5. New URLs: `/events/{id}` and `/people/{slug}` (with a language prefix for other languages)
   show an event or a published profile. They only answer when the event or profile exists, so a
   page at such a path keeps working. Event card titles and person card names now link there
   (the external event link stays in the card footer); both appear in `sitemap.xml`. Templates
   with their own event or person markup can link to `nibblyEventPath()` and `nibblyPersonPath()`.
6. Dashboard. The People and Menus & Footer items can be switched off under Settings → Modules
   (`modules.people`, `modules.menus`). Settings → Menus moved to Menus & Footer (old links
   redirect). The page editor's language and page selects in the top bar are gone; every editor
   has a language switch top right instead. New news versions in another language start as
   drafts.
7. Site CSS. Text sizes, spacing and containers in `css/style.css` and `css/components.css` are now
   in rem and the root size grows above a 1600 px viewport; `.content-inner` is wider
   (`--container-content: 60rem`). Check site styles that relied on the old pixel values.

## Upgrading from 2.4.2 to 2.4.3

Version 2.4.3 is a compatible release: large image uploads get a web version, the image upload
limit is a setting, and the PHP upload limit is shown and named. No manual step is needed.

1. Deploy the complete core. New: `includes/media-web-version.php`; changed: `admin/api.php`,
   `admin/api/media.php`, `admin/api/bootstrap.php`, `admin/api/settings.php`,
   `js/image-manager.js`, `css/image-manager.css`, `includes/system-status.php`, the dashboard,
   `.htaccess` (a commented upload-limit example only) and `admin/lang/*.json`.
2. A new folder `assets/images-originals/` (and `assets/images-originals-trash/`) appears next
   to `assets/images/` when the first large image is uploaded; the web server must be able to
   create it (same rights as `assets/images/`). Include it in deployments and backups like the
   images folder; the `-trash` rule in `.htaccess` already keeps the trash folder private.
3. Uploads of JPEG, PNG or WebP files that are larger than 400 KB or wider than the configured
   longest side are stored as `<name>.webp`; the API's `upload-media` response and the library
   return that name, so code that assumed the uploaded extension must use the returned `name`
   and `path`. Files already in the library are not touched. Set `media.webVersion` to `false`
   to store uploads as they are.
4. The image limit defaults to 20 MB (was 5 MB). PHP still caps uploads through
   `upload_max_filesize` and `post_max_size` (often 2M/8M); the system status shows both, the
   library shows the lower one, and `.htaccess` has a commented `php_value` example for Apache
   module hosts (PHP-FPM hosts use `.user.ini` or the hosting panel). Re-encoding a 15 MB photo
   needs memory: Nibbly raises `memory_limit` for the request when the server allows it and
   otherwise stores the upload unchanged.
5. The development server: `php -S` uses the CLI `php.ini`; start it with
   `php -d upload_max_filesize=20M -d post_max_size=25M -S localhost:3000 router.php` to test
   large uploads.

## Upgrading from 2.4.1 to 2.4.2

Version 2.4.2 is a compatible release: a sixth dashboard theme (Pop), three local fonts, a
text-size setting and two small admin fixes. No manual step is needed.

1. Deploy the complete core, including `admin/fonts/`. Three font files are new
   (`ibm-plex-sans-variable-*.woff2`, `atkinson-hyperlegible-next-variable-*.woff2`,
   `public-sans-variable-*.woff2`) and `admin/fonts/geist-variable.woff2` is gone; delete it on
   the server if your deployment does not remove files. `THIRD-PARTY-NOTICES.md` lists the
   licences (SIL Open Font License).
2. Theme settings. `theme.preset` accepts `pop` in addition to `soft`, `classic`, `paper`,
   `studio` and `console`; `theme.font` accepts `plex`, `atkinson` and `public` and no longer
   `geist` (a stored `geist` reads as `plex` until the settings are saved again); the new key
   `theme.fontSize` (`small`, `default`, `large`, default `default`) scales the dashboard's type
   steps through `--nb-font-scale`. An installation without the key keeps the default size.
3. Site CSS that targets admin chrome. The topbar breadcrumb now uses `--nb-topbar-text-muted`;
   the Pop theme paints the sidebar with `--nb-sidebar-gradient` (built from `--nb-sidebar-bg`,
   so a custom sidebar colour keeps the gradient). Nothing changes for the other themes.

## Upgrading from 2.4.0 to 2.4.1

Version 2.4.1 is a compatible release of fixes: visit counting no longer depends on a site's
`includes/header.php`, the media library preselects the clicked image, editable button links
keep their layout, the sidebar's collapse button got its panel icon, and the agent status in the
AI settings stays visible. No manual step is needed.

1. Deploy the complete core. `route.php`, `index.php`, `router.php` and
   `includes/analytics-helper.php` changed: the router schedules the visit count as a fallback at
   the end of the request, so a site whose header file lacks `nibblyAnalyticsTrack()` counts
   visits from now on. Nothing is counted twice; error pages (404) are no longer counted.
2. Sites that ship their own `includes/header.php` should still rebase it on the core file when
   convenient: the header also renders the SEO tags, the theme attributes and the admin bar.
3. Buttons. Primary buttons now carry their hover in the fill colour (a brief lift, then a settle)
   and the glow is a sheen over that colour, so "Glow" off removes sheen, shadows and text shadow
   from real buttons and the preview alike. Site CSS that set `background` on `.btn-primary` or
   `.nb-btn--primary` should set `background-color` instead and leave `background-image` to the
   sheen token (`--nb-primary-sheen`, `none` in Studio and Console). The hover colours and timing are
   tokens (`--nb-hover-lift`, `--nb-hover-settle`, `--nb-hover-tint`, `--nb-duration-hover-in`,
   `--nb-duration-hover-out`, `--nb-ease-overshoot`).

## Upgrading from 2.3.2 to 2.4.0

Version 2.4.0 is a compatible release: the dashboard got five themes, one base colour with
derived tints, density and font settings, a collapsible sidebar, editable semantic colours and
several bypass links for maintenance mode. The documented admin API is unchanged. No manual
step is needed; saved settings keep working.

1. Deploy the complete core. New core files: `admin/fonts.css`, `admin/fonts/*.woff2` (Geist,
   Geist Mono, Inter; see `THIRD-PARTY-NOTICES.md`), `css/nibbly-admin-tokens.css` (rewritten),
   `css/nb-select.css` (reads the control tokens). `admin/lang/*.json` gets new texts.
2. Theme settings. `theme.primaryColor` is the base colour; `theme.accentColor`, `theme.sidebarBg`
   and `theme.darkSidebarBg` are optional overrides (empty = derived), `theme.darkPrimaryColor` and
   `theme.darkAccentColor` are accepted but ignored (dark mode lifts the base colour itself). New
   keys: `theme.preset` (`soft`, `classic`, `paper`, `studio`, `console`), `theme.density`,
   `theme.font` (`system`, `geist`, `inter`, `geist-mono`), `theme.dangerColor`,
   `theme.warningColor`, `theme.successColor`. An installation without them gets Soft, auto density,
   the system font and the default semantic colours. A light base colour that an earlier version
   darkened on save keeps its stored value; set it again to get the chosen colour back.
3. Maintenance bypass. Links now live in `access.maintenance.bypassLinks` (`param` and `key`,
   stored as entered so the dashboard can show them). A key hashed by an earlier version
   (`bypassKeyHash`) keeps working until the Access page is saved once; that save replaces it with
   the visible links, so enter the keys you hand out before saving. The guard accepts any stored
   link; the bypass still sticks to the session.
4. Site CSS that styled admin elements through fixed pixel values or the old `--nb-*` colours
   should move to the tokens: radii (`--nb-radius-sm/md/lg/xl`), spacing (`--nb-space-*`), control
   heights (`--nb-control-h`, `--nb-control-h-sm`) and `--nb-text-on-primary` for text on the
   primary colour, which is dark on light primaries.

## Upgrading from 2.3.1 to 2.3.2

Version 2.3.2 is a compatible release: the AI agent can chat in the dashboard, the image models of
Kie.ai, fal.ai and Comet were renewed, and the image tool stays visible while its service cannot
work yet. No manual step is needed.

1. Deploy the complete core. `admin/lang/*.json` gets new texts; `js/ai-agent.js`, `js/ai-copilot.js`,
   `css/ai-copilot.css` and the dashboard scripts changed. A site that copies `js/ai-copilot.js` or
   `js/ai-agent.js` into its own template must take the new versions: the dashboard now loads both, and the
   agent registers in the dashboard mode as well.
2. Image models. Saved Kie.ai model ids are read through aliases (`gpt-image-2` becomes
   `gpt-image-2-5-flare`, `nano-banana-2` becomes `nano-banana-2-1`), so nothing needs saving again. A fal.ai
   or Comet model that is no longer listed (for example `fal-ai/nano-banana-2`, `fal-ai/flux/schnell`,
   `gemini-3.1-flash-image-preview`) keeps working as a typed model ID with the service defaults; choose one of the
   new models to get its exact parameters. Prices you entered for a model stay. Kie.ai prices come from its marketing
   pages, not from its API documentation, and may change: override them in the settings when they matter.
3. Custom code that calls `nibblyAiGenerateKieImages()` with the former model ids keeps working through the aliases.
   `nibblyAiKieProviderOutputFormat()` is gone (replaced by `nibblyAiKieTaskInput()`).
4. `nibblyAiImageServiceResolve()` now returns the adapter and address per model (`nibblyAiImageServiceWithModel()`);
   code that read `$service['adapter']` of a Comet service must resolve with the model in use.

## Upgrading from 2.3.0 to 2.3.1

Version 2.3.1 changes how the AI settings store models and prices, adds the safety check
of the AI agent, the Nibbly map and a separate image service. No manual step is needed for the settings: existing models,
credentials, limits and request behavior stay as they are. The documented admin API is unchanged.

1. Prepare as for any update (backup, preserve site-owned files) and deploy the
   complete core. New core files: `includes/ai/model-capabilities.json`,
   `includes/ai/model-capabilities.php`, `includes/ai/pricing-catalog.json` and
   `includes/ai/pricing.php`.
2. Models and reasoning effort. A saved `content/ai-settings.json` keeps its
   `chatModel` and `textModel`; they become the entry of the active provider in the new
   `providerModels`. The new presets only apply to installations without saved models
   and to a provider that has none saved yet. Without a stored `reasoning` section the
   installation runs in "legacy" mode: no effort parameter is sent, exactly as before,
   and the settings show the extra choice "Previous behavior (provider default)". Choose
   "Recommended" (or a level) per purpose to opt in. Note that Anthropic's own default
   effort on Sonnet 5.5 is `high`, so "Recommended" (medium) is cheaper than omitting
   the parameter.
3. Prices. The former global prices (`pricing.inputCentsPerMillion`,
   `outputCentsPerMillion`, euro cents per 1M tokens) become own prices for the chat
   and text model of the active provider, in EUR, marked "carried-over estimate", so
   the estimate for these models does not change. Delete such a price in the settings to
   use the catalog price. Other models use the catalog price or are shown as "Price not
   on file". Saving the settings once writes the new `pricing.models` section and drops
   the old pair. Older API callers that still send the pair keep working.
4. Costs are now stored with four decimals of a cent (`estimatedCostCents` in
   `content/ai-usage.json` and the audit log may be fractional; reservations too). Old
   whole-cent values stay valid. New ledger entries also carry `model`, `priced` and
   `priceBasis`; entries and buckets from before have none. Months of usage now count
   requests without a known price in `unpricedRequests`.
5. Code that calls the AI gateway can pass `purpose` (`chat` or `text`; text functions
   default to `text`, everything else to `chat`) to pick the reasoning level.
   `nibblyAiEstimateTextCost()` still exists, now with the price of the model.
6. `ai-settings.json` gains `reasoning`, `providerModels` and `pricing.models`. Keep
   them in version control and deployments with the file; keys stay in
   `content/ai-secrets.json`.
7. Safety check and Nibbly map. New core files: `includes/ai/agent/safety.php`,
   `includes/ai/agent/safety-patterns.php` and `includes/ai/nibbly-map.php`;
   `admin/lang/*.json` gets new texts.
8. `ai-agent-apply` takes an optional `confirmRisk=1`. A package with safety notes is
   refused (`needsRiskConfirm` in the response data) without it. Custom code that calls
   the endpoint for packages must show the notes and send the flag only after the
   person confirmed them.
9. The agent now refuses requests that would empty a page or the site and stops
   operations that remove, hide or empty a large share of a page. This is intended;
   work in smaller steps. The thresholds are the constant
   `NIBBLY_AGENT_SAFETY_LIMITS` in `includes/ai/agent/safety.php` and may be lowered,
   not raised, by site code. Suggestions and drafts of the classic Copilot go through the same
   text check: harmful text is dropped, odd text carries a safety note.
10. Image service. No manual step: `imageService.provider` defaults to `inherit`, which keeps
    the chat provider's own image models and the flat `imageModel`. New core files:
    `includes/ai/image-services.php` and `includes/ai/image-services.json`;
    `admin/lang/*.json` gets new texts. `content/ai-settings.json` gains `imageService`
    (choice, model per service, custom service) and `pricing.imageModels`; keys of image
    services go to `content/ai-secrets.json` under `imageServices` (owner-only, not in
    version control). The agent's image tool now needs a usable image service with a key;
    with Anthropic as chat provider, choose one under AI settings -> Images. fal.ai and Comet
    have no preset price: set a price per image for the model or keep the default price
    per image. Sites that replace `nibblyAiDownloadProviderImage()` or call it with one
    argument keep working (the second argument is optional).
11. Result images of providers are now fetched with the hardened fetch of `includes/ai/agent/net.php`:
    only public hosts and the ports 80, 443, 8080 and 8443 (new option `extraPorts` of
    `nibblyNetSafeFetch()`). A provider that serves its images from another port needs the
    image host on one of those ports. Model ids lose empty and `.`/`..` path segments when saved.
12. Image service extras. New: the agent's `generate_image` takes `references`; image prices may
    carry `byResolution` and `byQuality` tables (`pricing.imageModels` and the registry's `price`);
    `nibblyAiImagePrice()` takes an optional request array, and `nibblyAiImagePriceTable()` is the
    table behind it. The admin API gets `ai-image-models` (admin only, POST with CSRF token), which
    writes `content/ai-image-models-cache.json` (already in `.gitignore`, safe to delete). Ledger entries
    of images carry `priceBasis.centsPerImage` and `.basis` instead of `.perImage`.

### Supported models

| Provider (official host) | Models with a reasoning setting | Levels offered |
|---|---|---|
| OpenAI (`api.openai.com`) | `gpt-6.1-sol`, `gpt-6-luna` | Low, Medium, High |
| Anthropic (`api.anthropic.com`) | `claude-sonnet-5-5`, `claude-haiku-5-5` | Low, Medium, High |

Prices in the catalog were checked on 2026-10-16 against the providers' documentation
(see `includes/ai/pricing-catalog.json`). The exchange rate is the ECB reference rate
of 2026-10-15 and ages; set an own rate in the settings when it matters. Extend the
JSON files to add models. `none`, `minimal`, `xhigh` and `max` are not offered.

## Upgrading from 2.2.0 to 2.3.0

Version 2.3.0 adds compatible features and fixes: the optional AI agent in the
assistant, actions of the dashboard assistant, the search engine check in the
system status, and corrections to the page list and to saving settings. The
documented admin API, accounts and sessions are unchanged. New storage is created
on first use, and the AI API keys move to their own file (step 6). One behaviour
changes: `seo.noindexSite` now blocks the whole site (step 3).

1. Prepare as for any update: make and verify a full backup, pause editing and
   scheduled jobs, and preserve site-owned files (see
   [Before replacing files](#before-replacing-files)).
2. Deploy the complete core as described in
   [Deploy the complete core](#deploy-the-complete-core). New core files are
   `admin/api/agent.php`, the directory `includes/ai/agent/`,
   `includes/ai/dashboard-actions.php`, `includes/ai/settings-store.php`,
   `includes/seo-check.php` and `js/ai-agent.js`.
3. Check the search engine block before the update. Until 2.2.0, the setting
   "Block this website for search engines" (Settings → Branding → Search engines,
   `seo.noindexSite`) only emptied `robots.txt`; pages stayed indexable. Now it also
   sends `noindex, nofollow` in the robots meta tag and in an `X-Robots-Tag` header,
   empties `sitemap.xml` and leaves the sitemap out of `robots.txt`. Turn it off on
   a live site. After the update, the dashboard shows a banner while the live site
   blocks search engines, and System status → Search engines lists what the site
   really delivers (`robots.txt`, robots meta tag, `X-Robots-Tag`, `sitemap.xml`,
   the website address, maintenance mode).
4. If the site has its own copies of `includes/header.php`, `includes/footer.php`,
   `includes/page.php`, `includes/news-post.php` or `route.php`, merge the 2.3.0
   additions. Without them the site keeps working, but the agent is limited:
   - `header.php`, `page.php`, `news-post.php`, `route.php`: the block that loads
     `includes/ai/agent/preview.php` when `nb_agent_preview` is in the URL, and the
     preview bar in `header.php`. Without it, a preview of an unapplied package
     shows the stored page, and new pages are not found.
   - `footer.php`: the loader of `js/ai-agent.js` with `window.NB_AI_AGENT`, and the
     condition that keeps the inline editor and its CSRF token out of a package
     preview (`nibblyAgentPreview`). Without the loader, the agent mode is not
     offered.
5. New storage, created on first use. Keep it with `content/` in backups and
   deployments, and never in the web root's public files: `content/ai-agent/` (runs,
   change sets, staged images, snapshots, journal, rate limits and the page
   structure cache; `.secret` signs the undo tokens, so an open package cannot be
   undone without it), `content/ai-secrets.json` (the API keys, see step 6),
   `content/ai-model-quirks.json` (what a provider model accepts) and
   `content/seo-check.json` (result of the search engine check). Optional
   per-page restrictions for the agent live in `content/ai-schema/`. The shipped
   `content/.htaccess` denies direct access; on other servers confirm that
   `content/` cannot be fetched.
6. API keys now live in their own file. The provider keys, the Brave Search key and
   the Pexels key move from `content/ai-settings.json` to `content/ai-secrets.json`
   (owner-only, listed in `.gitignore`); the settings file then holds no key and can
   be committed. Nothing needs to be done: keys that the old file still holds move
   once when an administrator opens the dashboard or the AI settings. Afterwards,
   copy, deploy and back up the two files together; a copy of `ai-settings.json`
   alone no longer carries the keys, and `ai-secrets.json` must never be committed
   or served. A complete backup of `content/` contains both. Code that reads the
   settings file itself should call `nibblyAiReadStoredSettings()` instead.
7. The AI agent is off by default. To use it, enable it under Settings → AI Settings →
   "AI agent". It needs a provider with tool calling. OpenAI is addressed through the
   Responses API. Web research (Brave Search) and stock photos (Pexels) need their own
   keys and are optional. New default limits (64,000 input tokens, 16,000 output
   tokens, 300 requests and 250 text requests per day) apply to new installations;
   saved values are not changed, and "Use recommended limits" in the AI settings
   adopts them.
8. Sites that link language versions with different URL paths through `$PAGE_MAPPING`
   in `includes/nav-config.php` now see them as one page in Dashboard → Pages. Page
   files, slugs and public URLs are not touched, and `nav-config.php` needs no change.

After deployment, confirm that the Dashboard reports **2.3.0**, sign in through
`/admin/`, open Pages and check that translations with different paths show
"Edit", save colours in Settings → Dashboard, and run System status →
Search engines. If the agent is enabled, prepare a small change on a test page,
open its preview and undo it.

## Upgrading from 2.1.0 to 2.2.0

Version 2.2.0 is a compatible security release; install it promptly. The documented
API, storage formats, accounts and sessions are unchanged. It closes an upload
validation gap, a stored cross-site-scripting vector through uploaded SVG files and
dangerous link schemes in page content, and it adds a security check to System status.

1. Prepare as for any update: make and verify a full backup, pause editing and
   scheduled jobs, and preserve site-owned files (see
   [Before replacing files](#before-replacing-files)).
2. Deploy the complete core as described in
   [Deploy the complete core](#deploy-the-complete-core). New core files are
   `assets/.htaccess` (a hidden file: upload it, and keep it when you replace the
   `assets/` folder), `includes/security-check.php` and `includes/svg-sanitizer.php`.
   `router.php` changed as well. `SYSTEM-REVIEW.md`, `SYSTEM-IMPLEMENTATION.md` and
   `llms-full.txt` were removed and can be deleted.
3. Open System status after the update and run **Security → Check now**
   (administrators only). A proxy that serves static files itself, for example nginx on
   HestiaCP or Plesk with "Serve static files directly by nginx", ignores `.htaccess`
   and can expose `content/` (password hashes, the SMTP password, form submissions)
   and `backups/`. If the check reports exposed folders, add the nginx rules for
   `content/`, `backups/`, `cli/`, `tests/` and the media trash folders from
   [ROUTING.md](ROUTING.md), reload nginx and check again. The check stores its
   result in `content/security-check.json`; keep that file out of Git (it is in
   `.gitignore`).
4. Uploads are stricter. Images and audio whose filename extension is not an allowed
   media type are refused, even when the content looks valid, and uploaded SVG files
   are sanitised (scripts, `foreignObject`, event handlers and `javascript:` links are
   removed, SVGs with a DTD are refused). SVG files that already lie in `assets/` are
   not rewritten: replace any that came from an untrusted source.
5. Link targets in page content only keep `http(s)`, `mailto`, `tel`, relative and
   anchor addresses. A stored `javascript:`, `data:` or `vbscript:` link now renders
   without a target. Customized templates that print link targets themselves should
   use `nibblySanitizeHref()`.

After deployment, confirm that the Dashboard reports **2.2.0**, sign in through
`/admin/`, upload a PNG and an SVG in the media library, and read the result of the
security check in System status.

## Upgrading from 2.0.0 to 2.1.0

Version 2.1.0 adds compatible features and fixes. The documented API, storage
formats, accounts and sessions are unchanged.

1. Prepare as for any update: make and verify a full backup, pause editing and
   scheduled jobs, and preserve site-owned files (see
   [Before replacing files](#before-replacing-files)).
2. Deploy the complete core as described in
   [Deploy the complete core](#deploy-the-complete-core). New core files are
   `includes/ai-labels.php`, `includes/routing-helper.php`, `css/ai-labels/` and
   `js/ai-labels.js`.
3. Merge the updated `.htaccess` with any site-specific rules and upload it with
   the core, including hidden files. Routing now canonicalizes PHP directory
   entry points and file/directory name collisions (HTTP 308) and sends admin
   logins to `dashboard.php`. Run the Apache and post-upload checks in
   [ROUTING.md](ROUTING.md#required-verification).
4. If the site has a customized `includes/footer.php`, merge two additions from
   the 2.1.0 footer: the `js/ai-labels.js` loader (loaded only when
   `nibblyAiLabelsRendered()` is true) and `window.NB_AI_LABELS` in the editor
   script block. Without them, labels still render, but touch devices cannot
   reveal them and the visual editor updates them only after a reload.
5. AI disclosure labels are stored in the site-owned `content/media-meta.json`,
   created when the first label is set. Keep it with `content/` in backups and
   deployments. Existing images stay unlabelled until an editor marks them in
   the media library; uploads with embedded provenance are labelled
   automatically. The label artwork is English ("AI generated") on every page;
   for the German artwork on German pages, choose Settings → Language → AI
   labels on images → Page language. Labels are hidden at rest and shown when a
   visitor lingers; set `--nb-ai-label-rest` (for example `0.25`) to keep them
   visible. Labelled images need a positioned frame, see [AI Disclosure
   Labels](AI-AGENT-GUIDE.md#ai-disclosure-labels).

After deployment, confirm that the Dashboard reports **2.1.0**, sign in through
`/admin/`, save a page in both editors, open the saved backups in the admin bar,
and set and remove an AI label on a test image.

## Upgrading to 2.0.0

Version 2.0.0 changes the documented admin save API to prevent concurrent edits
from overwriting one another. Existing content and accounts remain supported,
but custom API clients and customized editor footers need attention.

### Before replacing files

1. PHP 8.1 or newer is required. CI covers PHP 8.1, 8.4 and 8.5; PHP 8.4/8.5
   are recommended for production. PHP 8.1 remains a compatibility target, but
   its [official security support ended on 2025-12-31](https://www.php.net/eol.php).
   Older documentation claiming PHP 7.4 support was incorrect. Check the
   extensions listed in [README.md](README.md#requirements).
2. Make and verify a full backup of code, configuration, content and assets.
   Keep it outside the installation being updated. Rehearse the update on a copy.
3. Pause scheduled jobs and editing during deployment. Let active AI image jobs
   finish and reconcile uncertain provider requests before upgrading. Do not
   delete pending budget reservations or submit an ambiguous paid request again.
4. Preserve site-owned files according to the
   [upgrade boundaries](AI-AGENT-GUIDE.md#upgrading-nibbly). In particular, keep
   `content/`, `assets/`, language templates, `admin/config.php`, custom
   header/footer/navigation files and site-owned CSS/JavaScript.

### Deploy the complete core

Replace the core as a complete version, including `VERSION`, the new nested
`admin/api/` and `admin/dashboard/scripts/` directories, `includes/ai/`, shared
helpers and core editor CSS/JavaScript. Copying only `admin/api.php` or
`admin/dashboard.php` is insufficient. Preserve the site-owned exceptions above;
do not unpack a release over a live installation without applying these rules.

Merge and upload `.htaccess` together with routing/login changes; confirm the
upload includes hidden files and preserve site-specific rules. The
`admin/dashboard/` fragment directory collides with the old clean login target:
the default destination must be `dashboard.php`, with both clean aliases handled
explicitly. Follow [ROUTING.md](ROUTING.md) for the required Apache and post-upload
checks. A successful `/admin/` login-page request alone does not verify login or
dashboard routing.

The bundled dashboard and inline editor already implement revision handling.
If your site has a customized `includes/footer.php`, merge the editor integration
from the 2.0.0 footer into it while preserving the site's layout:

- Use the shared session/access helpers to decide whether editor assets load.
- Set `window.NB_ADMIN_API_URL` to the correct admin endpoint, including any
  installation subdirectory, and load the translation data in `window.NB_LANG`.
- Load `css/revision-client.css` and `js/revision-client.js` before
  `js/inline-editor.js` and before code that loads or saves editable resources.
  Keep these assets restricted to authenticated editors, as in the bundled footer.
- Update the remaining core editor assets together and invalidate browser/CDN
  caches. Older cached editor code can otherwise fail to supply revisions.

Existing accounts and passwords remain valid. Users must sign in again because
sessions are now bound to the current account password and permissions.

### Custom API integrations

Use the same authenticated session and the existing CSRF protection. The request
sequence is:

| Resource | Load action | Save action | Save fields |
| --- | --- | --- | --- |
| Page | `load` with `page` | `save` | `page`, JSON `content`, `revision`, `csrf_token` |
| Settings | `load-settings` | `save-settings` | JSON `settings`, `revision`, `csrf_token` |

1. Read the top-level `revision` returned by the load response and retain it with
   that editable snapshot.
2. Send it in the save POST form. After a successful save, retain the returned
   revision for subsequent changes.
3. On HTTP 409, retain the user's pending content, load the current version for
   comparison and explicitly reconcile the changes before another save.
4. On HTTP 428, obtain a loaded snapshot before editing/saving. Never fetch a
   newer revision solely to force an old snapshot through.

State-changing admin actions require POST. Editor-role settings responses omit
SMTP, backup and AI credential groups; credential administration requires an
administrator. Rich-text rendering uses an HTML allowlist, so unsupported layout
markup belongs in site-owned templates.

### Data changes and verification

Analytics migrate automatically from `content/analytics.json` to daily records
and historical archives under `content/analytics/`. The retained legacy data and
historical summaries remove visitor/session identifiers while preserving totals.
No manual JSON conversion is necessary. AI usage now contains persistent budget
reservations; keep that state and image job files together when backing up.

After deployment, verify login, a page save in both editors, a two-tab editing
conflict, settings, media upload and backup creation. Review System status and
check public pages in each site language. Confirm Dashboard reports **2.0.0**.
Re-enable scheduled jobs and editing after these checks pass.

To roll back, stop writers and restore the matching pre-upgrade code and data
together. Downgrading only PHP does not reverse the analytics migration. Changes
made after the backup need separate recovery or reconciliation before rollback.
