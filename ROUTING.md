# Routing and deployment verification

Read this before changing URLs, redirects, `.htaccess`, `router.php`,
`route.php`, login destinations, or the directory layout of request handlers.
Apply it to site migrations and core upgrades as well as routing fixes.

## Why local success is insufficient

Production routing has multiple layers: the hosting proxy, Apache's rewrite
and directory handling, and Nibbly's PHP front controller. The development
server executes `router.php` and does not interpret `.htaccess`. Passing local
PHP tests therefore does not prove that the uploaded installation routes
correctly. Verify affected routes under Apache and on the deployed host.

The concrete regression found in September 2026 was a filesystem collision:
`admin/dashboard.php` is the request handler, while `admin/dashboard/scripts/`
holds implementation fragments. Login redirected to `dashboard`. Apache
redirected `/admin/dashboard` to `/admin/dashboard/` with HTTP 301; that URL
then fell through to `route.php` and returned 404. `/admin/` continued to work
because it has an actual `index.php`. The PHP development server served the
slashless dashboard successfully and concealed the broken login flow.

The fix sends login directly to `dashboard.php` and redirects both clean URL
aliases to it. Do not change the destination back to `dashboard` without
reworking and testing the complete routing contract below.

Site-specific news/event detail routes can have a similar ordering problem
when physical listing templates compete with nested detail URLs. Preserve
the site's explicit detail rules when merging updated core rewrite rules.

## Routing contract

- Use explicit PHP URLs for internal admin endpoints that share their name
  with implementation directories. New fragment directories must not silently
  change existing public URLs. Check for collisions before adding them.
- Keep `/admin/` as a directory URL. The current dashboard destination is
  `/admin/dashboard.php`; `/admin/dashboard` and `/admin/dashboard/` are
  compatibility aliases that return HTTP 308 with query parameters preserved.
- `/admin` redirects to `/admin/`; `/login` and `/login/` are aliases for
  `/admin/`. Login aliases must not be accepted as post-login return targets.
- Physical directories with an `index.php` get their missing slash. If a PHP
  handler shares its name with a directory, both clean aliases resolve to the
  explicit `.php` handler. A PHP handler with an extra trailing slash also
  resolves to its explicit filename. These HTTP 308 redirects retain POST
  method/body and query parameters. They do not change JSON-backed page slugs.
- `includes/routing-helper.php` implements the shared PHP fallback used by
  `router.php` and `route.php`; `.htaccess` applies the equivalent rules before
  Apache's generic file/directory handling. Update these together and include
  the helper in upgrades.
- Canonicalization is route-specific. Do not add a blanket rule to append or
  remove slashes across the site, or disable directory handling globally to
  conceal one collision. A trailing slash changes how browsers resolve relative
  assets, forms, API requests and login redirects.
- Preserve existing security rules before routing shortcuts. Put explicit
  collision/detail rules before generic file/directory handling. Retain
  `Options -MultiViews` and the existing front-controller rewrite termination;
  changing either requires Apache verification.
- Keep Apache and `router.php` behavior aligned for paths they both serve.
  `route.php` handles dynamic content; it should not interpret admin fragment
  directory names as public page slugs.
- Consider cached permanent redirects. Do not introduce an inverse redirect
  that loops with an older cached 301. The explicit `dashboard.php` target
  avoids the previous `/admin/dashboard` to `/admin/dashboard/` redirect.
- Merge `.htaccess` deliberately during upgrades and include hidden files in
  upload checks. Preserve customer security, redirects and news/event rules.
  Uploading only PHP files can leave obsolete production routing in place.
- The bundled Apache substitutions use root-absolute paths. Do not claim
  subdirectory hosting works based on a root installation test; verify rewrite
  targets, cookies, assets and redirects under the actual deployment prefix.

## Required verification

For routing or request-handler directory changes, run the focused regression:

```sh
python3 tests/system-smoke.py
python3 tests/routing-smoke.py
python3 tests/routing-smoke.py --apache
```

These suites use disposable accounts and content, never live credentials.
The default routing suite runs on the PHP development server; `--apache` runs
the same contract against Apache and PHP-FPM on temporary loopback ports using
the candidate `.htaccess`. It does not alter the machine's web-server services.
Set `HTTPD`, `PHP_FPM` and `HTTPD_MODULE_DIR` if their paths are not detected.
The separate Apache CI job runs this fixture too. Do not describe PHP-only
results as Apache coverage. Repeat the relevant checks on the deployed host
after an authorized upload; local Apache cannot verify hosting proxy rules.

| Check | Expected result |
| --- | --- |
| Guest opens `/admin/` | Login form, HTTP 200 |
| `/admin`, `/login`, `/login/` | HTTP 308 to `/admin/`; final login form and CSS load |
| Successful login through the form | Redirect reaches the real dashboard, HTTP 200; no intermediate 404 or loop |
| `/admin/dashboard`, `/admin/dashboard/` | HTTP 308 to `/admin/dashboard.php`; query string preserved |
| Dashboard request without a session | Redirect to login; no dashboard data exposed |
| Dashboard request with a valid session | Dashboard HTML, HTTP 200 |
| Actual admin CSS, JS, form/API URLs after navigation | Correct paths and expected content types; authenticated API still works |
| Form POST to directory URL without slash | HTTP 308; followed request retains method/body and query |
| Add fragment directory beside an existing PHP endpoint | Both clean aliases resolve to `.php`; HTML and relative CSS still work |
| Existing language homepage and nested page, with/without slash | Established site behavior, correct assets and links |
| Existing news/event listing and detail URLs in each supported language | Established site behavior; detail request reaches its intended handler |
| Unknown public and admin URLs | HTTP 404, not a successful empty page |
| Config, content and implementation-only resources | Existing access protections remain effective |

Inspect the first response **and** the followed redirect chain. A final HTTP
200 alone may be a login or error page, so check the returned content. Use GET
requests, preserve session cookies during login tests, include a query string
such as `?tab=content&probe=a%2Fb`, and check both a fresh browser session and
previously used aliases. Verify relative assets from the final browser URL.

Record the tested code revision or file hashes, server/PHP versions, relevant
hosting prefix/proxy, request paths, statuses, Location headers and final page.
Keep test credentials and session cookies out of the evidence. If Apache or
post-upload checks have not run, state that explicitly as outstanding
verification rather than reporting a production fix as verified.

## Local verification — 2026-09-15

The candidate working tree passed all 19 smoke suites with PHP 8.5.4 and the
additional routing suite under Apache 2.4.67 + PHP-FPM 8.5.4 on macOS. The
installation was at the document root, on temporary loopback ports, without a
hosting proxy. The executable assertions in `tests/routing-smoke.py` record
the request paths, expected first-response statuses/Location values, followed
login/API content, CSS types, and POST payload retention. No live host or
subdirectory installation was tested. The new GitHub Apache job is configured;
a remote CI run has not been triggered from this local check.

Tested SHA-256 values:

```text
.htaccess                    1e8a86df0fb6304f7c6cc0189ad2813e0e657c4a49ba4694750d8241a5840d42
includes/routing-helper.php  c2d7aa3ff89fbf7ae89d06208e8c94801948d98ea1abfd0f008a7ef1e97b3fc0
router.php                  831613fc9e35f2c252d7ebe52a56c8d01e2b8af427ae0c16470de766a6f64e6f
route.php                   9709439626c7cbf7716154180f9e8244894a9580815c120fd6c9a799d3312033
```
