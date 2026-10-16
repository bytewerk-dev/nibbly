# Upgrading Nibbly

Read every section between the installed version and the target version, oldest
first. Installations before 2.0.0 start with [Upgrading to 2.0.0](#upgrading-to-200).

## Upgrading from 2.3.0 to the next release

The next release changes how the AI settings store models and prices, adds the safety check
of the AI agent and the Nibbly map. No manual step is needed for the settings: existing models,
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
