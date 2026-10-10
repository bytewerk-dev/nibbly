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
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, max-age=0');
    }
    return true;
}

/** Banner shown above the page while previewing. */
function nibblyAgentPreviewBanner(): string {
    $lang = (string)($GLOBALS['currentLang'] ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'));
    $text = $lang === 'de'
        ? 'Vorschau: Diese Änderungen sind noch nicht übernommen. Nur du siehst diese Seite so.'
        : 'Preview: these changes have not been applied yet. Only you see the page like this.';
    return '<div class="nb-agent-preview-banner" role="status" style="position:sticky;top:0;z-index:2147483000;padding:.6rem 1rem;'
        . 'background:#1f2933;color:#fff;font:600 14px/1.4 system-ui,sans-serif;text-align:center">'
        . htmlspecialchars($text) . '</div>';
}
