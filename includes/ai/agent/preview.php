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
 * Bar above the page while previewing: what this is, and the way back. "Apply changes" does not
 * apply anything here; it asks the assistant tab (same browser, BroadcastChannel) to open its own
 * confirmation, so the confirmation, the undo and the review stay in one place.
 */
function nibblyAgentPreviewBanner(): string {
    $id = (string)($GLOBALS['nibblyAgentPreview']['id'] ?? '');
    $text = [
        'banner' => nibblyAgentPreviewText('agent.preview_banner', 'Preview: these changes have not been applied yet. Only you see the page like this.'),
        'apply' => nibblyAgentPreviewText('agent.apply', 'Apply changes'),
        'back' => nibblyAgentPreviewText('agent.preview_back', 'Back to the assistant'),
        'sent' => nibblyAgentPreviewText('agent.preview_sent', 'Sent to the assistant. Confirm the changes there.'),
        'close' => nibblyAgentPreviewText('agent.preview_close_hint', 'Close this tab to get back to the assistant.'),
        'none' => nibblyAgentPreviewText('agent.preview_no_assistant', 'The assistant is not open in any tab any more. Open it on your site again and apply the package there.')
    ];
    $button = 'font:600 13px/1 system-ui,sans-serif;padding:.45rem .8rem;border-radius:6px;border:1px solid #fff;cursor:pointer;margin:0 .25rem';
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
    return '<div class="nb-agent-preview-banner" id="nb-agent-preview-banner" role="status" style="position:sticky;top:0;z-index:2147483000;padding:.6rem 1rem;'
        . 'background:#1f2933;color:#fff;font:600 14px/1.4 system-ui,sans-serif;text-align:center">'
        . '<span data-nb-preview-text>' . htmlspecialchars($text['banner']) . '</span>'
        . '<span style="display:inline-block;margin-left:.75rem;white-space:nowrap">'
        . '<button type="button" data-nb-preview="apply" style="' . $button . ';background:#fff;color:#1f2933">' . htmlspecialchars($text['apply']) . '</button>'
        . '<button type="button" data-nb-preview="back" style="' . $button . ';background:transparent;color:#fff">' . htmlspecialchars($text['back']) . '</button>'
        . '</span></div>'
        . '<script>(function(){var bar=document.getElementById("nb-agent-preview-banner");if(!bar)return;'
        . 'var id=' . json_encode($id, $flags) . ',text=' . json_encode($text, $flags) . ',label=bar.querySelector("[data-nb-preview-text]"),channel=null,timer=0;'
        . 'try{if(typeof BroadcastChannel==="function")channel=new BroadcastChannel("nibbly-agent-preview");}catch(e){}'
        . 'function say(message){label.textContent=message;}'
        . 'function leave(){window.close();}'
        . 'if(channel)channel.addEventListener("message",function(event){var d=event.data||{};if(d.type!=="ack"||d.id!==id)return;window.clearTimeout(timer);say(text.sent);window.setTimeout(leave,600);});'
        . 'bar.addEventListener("click",function(event){var b=event.target.closest("[data-nb-preview]");if(!b)return;'
        . 'if(b.getAttribute("data-nb-preview")==="back"){leave();window.setTimeout(function(){say(text.close);},300);return;}'
        . 'if(!channel){say(text.none);return;}'
        . 'channel.postMessage({type:"apply",id:id});window.clearTimeout(timer);timer=window.setTimeout(function(){say(text.none);},1500);});'
        . '})();</script>';
}
