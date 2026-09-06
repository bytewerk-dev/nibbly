# Upgrading to Nibbly 2.0.0

Version 2.0.0 changes the documented admin save API to prevent concurrent edits
from overwriting one another. Existing content and accounts remain supported,
but custom API clients and customized editor footers need attention.

## Before replacing files

1. Use PHP 8.4 or 8.5, the versions covered by CI. The language minimum is PHP
   8.1; older documentation claiming PHP 7.4 support was incorrect. Check the
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

## Deploy the complete core

Replace the core as a complete version, including `VERSION`, the new nested
`admin/api/` and `admin/dashboard/scripts/` directories, `includes/ai/`, shared
helpers and core editor CSS/JavaScript. Copying only `admin/api.php` or
`admin/dashboard.php` is insufficient. Preserve the site-owned exceptions above;
do not unpack a release over a live installation without applying these rules.

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

## Custom API integrations

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

## Data changes and verification

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
