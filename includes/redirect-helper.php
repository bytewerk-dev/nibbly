<?php
/**
 * Migration redirect registry.
 *
 * Reads `content/redirects.json` and applies the first matching rule
 * for the current request. Designed for SEO-preserving migrations from
 * other CMSs (WordPress, Joomla, hand-rolled HTML), where old URLs
 * need to map to new clean URLs — sometimes with anchor targets,
 * sometimes as 410 Gone for retired content.
 *
 * Schema (content/redirects.json):
 *
 *   {
 *     "redirects": [
 *       { "from": "^/old/path/?$",        "to": "/new",  "status": 301 },
 *       { "from": "^/wp/category/.*$",    "to": "/news",  "status": 301 },
 *       { "from": "^/wp/page/?$",         "to": "/about#section", "status": 302 },
 *       { "from": "^/retired/lifecoach$", "status": 410 }
 *     ]
 *   }
 *
 * - `from` is a PCRE pattern matched against the request path
 *   (without query string, with leading slash).
 * - `to` is the target URL. Anchors (#…) are preserved as-is — Apache
 *   would need `[NE]` to do this, here it just works.
 * - `status` is 301, 302, or 410. Default 301 if absent and `to` is
 *   present. 410 ignores `to` and renders /410.php.
 *
 * Apply by calling applyRedirects($requestUri) early in the front
 * controller — before any other routing — so a redirected URL never
 * accidentally matches a real page slug.
 */

function loadRedirects() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $file = __DIR__ . '/../content/redirects.json';
    if (!is_file($file)) {
        return $cache = [];
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data) || !isset($data['redirects']) || !is_array($data['redirects'])) {
        return $cache = [];
    }
    return $cache = $data['redirects'];
}

/**
 * Match a single redirect rule against a request path.
 * Returns the resolved [status, target] tuple on match, or null on miss.
 */
function matchRedirect($rule, $requestPath) {
    if (!isset($rule['from']) || !is_string($rule['from'])) return null;

    // Suppress warnings for invalid regex — a malformed rule shouldn't
    // crash the whole site. preg_match returns false on bad pattern.
    $matched = @preg_match('#' . str_replace('#', '\#', $rule['from']) . '#', $requestPath);
    if ($matched !== 1) return null;

    $status = isset($rule['status']) ? (int)$rule['status'] : 301;

    // 410 Gone — no target, server returns the branded 410 page.
    if ($status === 410) {
        return [410, null];
    }

    if (!isset($rule['to']) || !is_string($rule['to'])) return null;

    // Resolve back-references in the target (e.g. /news/$1).
    $target = preg_replace(
        '#' . str_replace('#', '\#', $rule['from']) . '#',
        $rule['to'],
        $requestPath
    );

    return [$status, $target];
}

/**
 * Apply the first matching redirect rule to the request and exit.
 * No-op if no rules are defined or none match — the caller continues
 * with normal routing.
 */
function applyRedirects($requestUri) {
    $path = parse_url($requestUri, PHP_URL_PATH) ?? '/';

    foreach (loadRedirects() as $rule) {
        $hit = matchRedirect($rule, $path);
        if ($hit === null) continue;

        [$status, $target] = $hit;

        if ($status === 410) {
            http_response_code(410);
            $errorCode = 410;
            include __DIR__ . '/../410.php';
            exit;
        }

        if ($status !== 301 && $status !== 302) {
            $status = 301;
        }
        header('Location: ' . $target, true, $status);
        exit;
    }
}
