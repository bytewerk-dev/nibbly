<?php
/**
 * Preview of a change set on the real page (agent phase P3).
 *
 * Opening any page with ?nb_agent_preview={changeset id} shows the page as it would look
 * after applying the package, but only
 *  - to the logged-in owner of the change set,
 *  - while the package is 'ready' and its base content is unchanged.
 * Everyone else, and every other case, sees the live content.
 *
 * The preview never writes (read-only mode), is not cacheable or indexable, shows a banner
 * and does not load the inline editor, so nobody can save staged content by accident.
 * It is started from includes/header.php, includes/page.php and includes/news-post.php.
 */

require_once __DIR__ . '/changeset.php';
require_once dirname(__DIR__, 2) . '/access-guard.php';

function nibblyAgentPreviewActive(): bool {
    return !empty($GLOBALS['nibblyAgentPreview']['active']);
}

/** Start the preview for this request if the URL asks for one and the visitor may see it. */
function nibblyAgentPreviewBoot(): bool {
    if (isset($GLOBALS['nibblyAgentPreview'])) return nibblyAgentPreviewActive();
    $GLOBALS['nibblyAgentPreview'] = ['active' => false];
    $id = $_GET['nb_agent_preview'] ?? '';
    if (!is_string($id) || !nibblyAgentValidChangesetId($id) || !nibblyAccessIsLoggedIn()) return false;

    $cs = nibblyAgentChangesetLoad($id);
    $user = nibblyAgentUserKey();
    if ($cs === null || $cs['status'] !== 'ready' || $user === '' || !hash_equals((string)$cs['owner'], $user)) return false;
    if (nibblyAgentConflicts($cs)) return false;
    $replay = nibblyAgentReplayOps($cs['ops']);
    if (!$replay['ok']) return false;

    $news = [];
    $events = [];
    foreach (nibblyAgentChangedFiles($replay['state']) as $file => $change) {
        if (($key = nibblyAgentParseTargetFromFile($file)) !== null) {
            nibblyContentOverlaySet($key, (array)$change['after']);
        } elseif (nibblyAgentTargetKindOfFile($file) === 'news') {
            $news[basename($file, '.json')] = (array)$change['after'];
        } elseif (nibblyAgentTargetKindOfFile($file) === 'events') {
            $before = [];
            foreach ((array)($change['before']['events'] ?? []) as $event) $before[(string)($event['id'] ?? '')] = $event;
            foreach ((array)($change['after']['events'] ?? []) as $event) {
                $eventId = (string)($event['id'] ?? '');
                if (($before[$eventId] ?? null) !== $event) $events[$eventId] = $event;
            }
        }
    }
    $GLOBALS['nibblyNewsOverlay'] = $news;
    $GLOBALS['nibblyEventsOverlay'] = $events;
    $GLOBALS['nibblyContentReadonlyRuntime'] = true;
    $GLOBALS['nibblyAgentPreview'] = ['active' => true, 'id' => $id];
    // Staged images are not in the library yet: the page loads them from the review endpoint instead.
    $staged = [];
    foreach ((array)$cs['media'] as $ref => $media) {
        $staged[(string)$media['finalPath']] = '/admin/api.php?action=ai-agent-media&amp;id=' . $id . '&amp;ref=' . $ref; // inside HTML attributes
    }
    if ($staged) ob_start(static fn(string $html): string => strtr($html, $staged));
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, max-age=0');
    }
    return true;
}

/** Text for the preview bar in the admin language (the page may be in another language). */
function nibblyAgentPreviewText(string $key, string $fallback): string {
    if (!function_exists('t')) {
        $i18n = dirname(__DIR__, 2) . '/admin/lang/i18n.php';
        if (is_file($i18n)) require_once $i18n;
    }
    if (!function_exists('t')) return $fallback;
    $text = t($key);
    return $text === $key ? $fallback : $text;
}

/**
 * The page the person came from (the assistant sends it as nb_agent_return): a path on this site, nothing else.
 * Empty when it is missing or not a plain local path.
 */
function nibblyAgentPreviewReturnPath(): string {
    $path = $_GET['nb_agent_return'] ?? '';
    if (!is_string($path) || $path === '' || strlen($path) > 500 || $path[0] !== '/' || str_starts_with($path, '//')) return '';
    if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path) || str_contains($path, '://')) return '';
    return preg_replace('/([?&])nb_agent_[a-z_]+=[^&#]*(&|$)/', '$1', $path) ?? '';
}

/**
 * Bar above the page while previewing: what this is, and the way on. Both buttons are plain links back to the
 * page the person came from; the assistant there (same tab, it still has the package) opens, and for "Apply
 * changes" asks for its own confirmation. The preview itself never applies anything.
 */
function nibblyAgentPreviewBanner(): string {
    $id = (string)($GLOBALS['nibblyAgentPreview']['id'] ?? '');
    $return = rtrim(nibblyAgentPreviewReturnPath(), '?&');
    if ($return === '') $return = (string)($GLOBALS['basePath'] ?? '') !== '' ? (string)$GLOBALS['basePath'] : './';
    $banner = nibblyAgentPreviewText('agent.preview_banner', 'Preview: these changes have not been applied yet. Only you see the page like this.');
    $apply = nibblyAgentPreviewText('agent.apply', 'Apply changes');
    $back = nibblyAgentPreviewText('agent.preview_back', 'Back to the assistant');
    $link = 'display:inline-block;font:600 13px/1 system-ui,sans-serif;padding:.5rem .85rem;border-radius:6px;border:1px solid #fff;text-decoration:none;margin:0 .25rem';
    $href = static fn(string $hash): string => htmlspecialchars($return . $hash, ENT_QUOTES);
    return '<div class="nb-agent-preview-banner" id="nb-agent-preview-banner" role="status" style="position:sticky;top:0;z-index:2147483000;padding:.6rem 1rem;'
        . 'background:#1f2933;color:#fff;font:600 14px/1.4 system-ui,sans-serif;text-align:center">'
        . '<span>' . htmlspecialchars($banner) . '</span>'
        . '<span style="display:inline-block;margin-left:.75rem;white-space:nowrap">'
        . '<a data-nb-preview="apply" href="' . $href('#nb-agent-apply=' . $id) . '" style="' . $link . ';background:#fff;color:#1f2933">' . htmlspecialchars($apply) . '</a>'
        . '<a data-nb-preview="back" href="' . $href('#nb-agent-open') . '" style="' . $link . ';background:transparent;color:#fff">' . htmlspecialchars($back) . '</a>'
        . '</span></div>';
}
