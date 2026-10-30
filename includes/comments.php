<?php
/**
 * Comments and guestbook: moderated visitor entries on posts and pages, stored flat-file.
 *
 * Storage: content/comments/{target}.json with {"target": "news:{post-id}" | "page:{page-key}",
 * "comments": [{id, parent, author, email, url, date, content, status, owner, source, ip?}]}.
 * Status: "pending" (awaiting moderation), "approved" (public), "spam" (kept for the moderator).
 * The file name is the post id for posts and "page_{page-key}" for pages, so an import can write
 * the archive directly (cli/import-wxr.php does).
 *
 * Templates call nibblyCommentsRender($type, $id, $options) where a post page or a guestbook page
 * wants the list and the form; the form posts to api/comment-submit.php (JSON, js/comments.js adds
 * reply handling and inline feedback, without JavaScript the endpoint answers with a plain page).
 *
 * Protection without external services (concept 7.1): one-time token with minimum age (form-protection),
 * honeypot, a simple arithmetic question signed with a server secret, link and character heuristics,
 * a word blocklist (content/comments-blocklist.json, optional), the shared rate limit of the forms,
 * and moderation: nothing is public before the owner approves it, except (optional) entries whose
 * name and e-mail were approved before.
 *
 * Settings (content/settings.json → "comments", all optional):
 *   enabled          true     the module as a whole
 *   moderation       true     new entries wait for approval
 *   autoApproveKnown true     a name + e-mail pair with an approved entry is approved at once
 *   maxLinks         2        more links than this are rejected
 *   minLength        3        shortest accepted text
 *   maxLength        6000     longest accepted text
 *   notify           true     e-mail to the site's recipient (settings → email) for every new entry
 *   closeAfterDays   0        0 = always open; otherwise posts older than this take no new comments
 */

require_once __DIR__ . '/json-store.php';
require_once __DIR__ . '/form-protection.php';
require_once __DIR__ . '/public-strings.php';
require_once __DIR__ . '/html-sanitizer.php';

function nibblyCommentsRoot(): string {
    return dirname(__DIR__);
}

function nibblyCommentsDir(): string {
    return nibblyCommentsRoot() . '/content/comments';
}

function nibblyCommentsSettings(bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $defaults = ['enabled' => true, 'moderation' => true, 'autoApproveKnown' => true, 'maxLinks' => 2, 'minLength' => 3, 'maxLength' => 6000, 'notify' => true, 'closeAfterDays' => 0];
    $path = nibblyCommentsRoot() . '/content/settings.json';
    $settings = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    $own = is_array($settings['comments'] ?? null) ? $settings['comments'] : [];
    $merged = $defaults;
    foreach ($defaults as $key => $default) {
        if (!array_key_exists($key, $own)) continue;
        $merged[$key] = is_bool($default) ? (bool)$own[$key] : max(0, (int)$own[$key]);
    }
    if ($merged['maxLength'] < 100) $merged['maxLength'] = 100;
    return $cache = $merged;
}

/** Normalised target: ['type' => 'news'|'page', 'id' => …, 'key' => 'news:…', 'file' => …]. */
function nibblyCommentsTarget(string $type, string $id): ?array {
    $type = $type === 'page' ? 'page' : 'news';
    $id = trim($id);
    if ($id === '' || !preg_match('/^[a-z0-9_-]{1,160}$/i', $id) || str_contains($id, '..')) return null;
    $file = nibblyCommentsDir() . '/' . ($type === 'news' ? $id : 'page_' . $id) . '.json';
    return ['type' => $type, 'id' => $id, 'key' => $type . ':' . $id, 'file' => $file];
}

function nibblyCommentsLoad(string $type, string $id): array {
    $target = nibblyCommentsTarget($type, $id);
    if ($target === null || !is_file($target['file'])) return [];
    $data = json_decode((string)file_get_contents($target['file']), true);
    $comments = is_array($data['comments'] ?? null) ? $data['comments'] : [];
    return array_values(array_filter($comments, 'is_array'));
}

/** Every stored comment with its target, newest first (dashboard). */
function nibblyCommentsAll(): array {
    $all = [];
    foreach (glob(nibblyCommentsDir() . '/*.json') ?: [] as $file) {
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || !is_array($data['comments'] ?? null)) continue;
        $targetKey = (string)($data['target'] ?? '');
        [$type, $id] = array_pad(explode(':', $targetKey, 2), 2, '');
        foreach ($data['comments'] as $comment) {
            if (!is_array($comment)) continue;
            $comment['targetType'] = $type;
            $comment['targetId'] = $id;
            $comment['target'] = $targetKey;
            $all[] = $comment;
        }
    }
    usort($all, static fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
    return $all;
}

function nibblyCommentsCountPending(): int {
    $count = 0;
    foreach (glob(nibblyCommentsDir() . '/*.json') ?: [] as $file) {
        $data = json_decode((string)file_get_contents($file), true);
        foreach (is_array($data['comments'] ?? null) ? $data['comments'] : [] as $comment) {
            if (is_array($comment) && ($comment['status'] ?? '') === 'pending') $count++;
        }
    }
    return $count;
}

/** Edit the comment list of a target under its lock; $update(array &$comments) returns false to abort. */
function nibblyCommentsUpdate(string $type, string $id, callable $update): bool {
    $target = nibblyCommentsTarget($type, $id);
    if ($target === null) return false;
    return nibblyJsonUpdate($target['file'], static function (array &$data) use ($update, $target): bool {
        $data['target'] = $target['key'];
        $comments = array_values(array_filter(is_array($data['comments'] ?? null) ? $data['comments'] : [], 'is_array'));
        $result = $update($comments);
        if ($result === false) return false;
        $data['comments'] = array_values($comments);
        return true;
    }, ['target' => $target['key'], 'comments' => []]);
}

function nibblyCommentsSetStatus(string $type, string $id, string $commentId, string $status): bool {
    if (!in_array($status, ['pending', 'approved', 'spam'], true)) return false;
    $found = false;
    $ok = nibblyCommentsUpdate($type, $id, static function (array &$comments) use ($commentId, $status, &$found): bool {
        foreach ($comments as &$comment) {
            if (($comment['id'] ?? '') === $commentId) { $comment['status'] = $status; $found = true; }
        }
        return $found;
    });
    return $ok && $found;
}

/** Delete a comment; its replies move up to the deleted comment's parent. */
function nibblyCommentsDelete(string $type, string $id, string $commentId): bool {
    $found = false;
    $ok = nibblyCommentsUpdate($type, $id, static function (array &$comments) use ($commentId, &$found): bool {
        $parentOfDeleted = '';
        foreach ($comments as $comment) if (($comment['id'] ?? '') === $commentId) { $found = true; $parentOfDeleted = (string)($comment['parent'] ?? ''); }
        if (!$found) return false;
        $comments = array_values(array_filter($comments, static fn($c) => ($c['id'] ?? '') !== $commentId));
        foreach ($comments as &$comment) if (($comment['parent'] ?? '') === $commentId) $comment['parent'] = $parentOfDeleted;
        return true;
    });
    if ($ok) {
        $target = nibblyCommentsTarget($type, $id);
        if ($target && is_file($target['file']) && !nibblyCommentsLoad($type, $id)) @unlink($target['file']);
    }
    return $ok && $found;
}

/** Approved comments of a target as a tree: each node carries 'children'. */
function nibblyCommentsTree(array $comments): array {
    $byParent = [];
    foreach ($comments as $comment) {
        if (($comment['status'] ?? '') !== 'approved') continue;
        $byParent[(string)($comment['parent'] ?? '')][] = $comment;
    }
    foreach ($byParent as &$list) usort($list, static fn($a, $b) => strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? '')));
    unset($list);
    $ids = [];
    foreach ($comments as $comment) $ids[(string)($comment['id'] ?? '')] = true;
    $build = static function (string $parent, int $depth) use (&$build, $byParent): array {
        $nodes = [];
        foreach ($byParent[$parent] ?? [] as $comment) {
            $comment['children'] = $depth < 6 ? $build((string)$comment['id'], $depth + 1) : [];
            $nodes[] = $comment;
        }
        return $nodes;
    };
    $roots = $build('', 1);
    // Replies whose parent is missing or not approved appear at the top level
    foreach ($byParent as $parent => $list) {
        if ($parent === '' || isset($ids[$parent]) && in_array($parent, array_map(static fn($c) => (string)$c['id'], array_filter($comments, static fn($c) => ($c['status'] ?? '') === 'approved')), true)) continue;
        foreach ($list as $comment) { $comment['children'] = []; $roots[] = $comment; }
    }
    return $roots;
}

function nibblyCommentsApprovedCount(string $type, string $id): int {
    return count(array_filter(nibblyCommentsLoad($type, $id), static fn($c) => ($c['status'] ?? '') === 'approved'));
}

// ---------------------------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------------------------

function nibblyCommentsEsc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nibblyCommentsFormatDate(string $date, string $lang): string {
    try {
        $dt = new DateTime($date);
    } catch (Exception $e) {
        return nibblyCommentsEsc($date);
    }
    if (function_exists('nibblyFormatNewsDate')) return nibblyFormatNewsDate($dt, $lang, true) . ', ' . $dt->format('H:i');
    return $dt->format($lang === 'de' ? 'd.m.Y, H:i' : 'Y-m-d H:i');
}

/** Secret for signing the arithmetic question (content/comments-secret.json, created once). */
function nibblyCommentsSecret(): string {
    static $secret = null;
    if ($secret !== null) return $secret;
    $path = nibblyCommentsRoot() . '/content/comments-secret.json';
    $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    if (is_array($data) && is_string($data['secret'] ?? null) && strlen($data['secret']) >= 32) return $secret = $data['secret'];
    $secret = bin2hex(random_bytes(32));
    nibblyJsonAtomicWrite($path, ['secret' => $secret]);
    return $secret;
}

/** A fresh arithmetic question: ['question' => '3 + 4', 'check' => signed answer]. */
function nibblyCommentsQuestion(string $token): array {
    $a = random_int(1, 9);
    $b = random_int(1, 9);
    return ['question' => $a . ' + ' . $b, 'check' => nibblyCommentsSign($token, (string)($a + $b))];
}

function nibblyCommentsSign(string $token, string $answer): string {
    return hash_hmac('sha256', $token . '|' . trim($answer), nibblyCommentsSecret());
}

/**
 * List and form for a target. Options: lang, basePath, title (list heading), formTitle, intro
 * (text above the form), emptyText, closed (bool: no form), labelReply, class (extra section class).
 */
function nibblyCommentsRender(string $type, string $id, array $options = []): string {
    $settings = nibblyCommentsSettings();
    $target = nibblyCommentsTarget($type, $id);
    if ($target === null || !$settings['enabled']) return '';
    $lang = (string)($options['lang'] ?? ($GLOBALS['currentLang'] ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en')));
    $basePath = nibblySafeFormBasePath((string)($options['basePath'] ?? ($GLOBALS['basePath'] ?? '')));
    $s = static fn(string $key) => nibblyPublicString($key, $lang);
    $comments = nibblyCommentsLoad($type, $id);
    $tree = nibblyCommentsTree($comments);
    $count = 0;
    $walkCount = static function (array $nodes) use (&$walkCount, &$count): void { foreach ($nodes as $n) { $count++; $walkCount($n['children']); } };
    $walkCount($tree);
    $title = (string)($options['title'] ?? ($type === 'page' ? $s('comments_guestbook_title') : $s('comments_title')));
    $sectionId = 'kommentare';

    $html = '<section class="nb-comments' . (!empty($options['class']) ? ' ' . nibblyCommentsEsc((string)$options['class']) : '') . '" id="' . $sectionId . '" data-comments-target="' . nibblyCommentsEsc($target['key']) . '" aria-labelledby="nb-comments-title">';
    $html .= '<h2 class="nb-comments__title" id="nb-comments-title">' . nibblyCommentsEsc($title) . ($count > 0 ? ' <span class="nb-comments__count">(' . $count . ')</span>' : '') . '</h2>';
    if ($tree) {
        $html .= nibblyCommentsRenderList($tree, $lang, $s('comments_reply'), 1);
    } else {
        $html .= '<p class="nb-comments__empty">' . nibblyCommentsEsc((string)($options['emptyText'] ?? $s('comments_empty'))) . '</p>';
    }
    if (empty($options['closed'])) {
        $html .= nibblyCommentsRenderForm($target, $lang, $basePath, $options);
    } else {
        $html .= '<p class="nb-comments__closed">' . nibblyCommentsEsc($s('comments_closed')) . '</p>';
    }
    $html .= '</section>';
    static $scriptAdded = false;
    if (!$scriptAdded) {
        $scriptAdded = true;
        $html .= '<script src="' . nibblyCommentsEsc($basePath) . 'js/comments.js" defer></script>';
    }
    return $html;
}

function nibblyCommentsRenderList(array $nodes, string $lang, string $replyLabel, int $depth): string {
    $html = '<ol class="nb-comments__list nb-comments__list--level-' . $depth . '">';
    foreach ($nodes as $comment) {
        $id = (string)($comment['id'] ?? '');
        $author = trim((string)($comment['author'] ?? '')) ?: nibblyPublicString('comments_anonymous', $lang);
        $owner = !empty($comment['owner']);
        $url = (string)($comment['url'] ?? '');
        $html .= '<li class="nb-comment' . ($owner ? ' nb-comment--owner' : '') . '" id="comment-' . nibblyCommentsEsc($id) . '">';
        $html .= '<article class="nb-comment__body">';
        $html .= '<header class="nb-comment__meta">';
        $html .= '<span class="nb-comment__author">' . ($url !== '' && preg_match('#^https?://#i', $url) ? '<a href="' . nibblyCommentsEsc($url) . '" rel="nofollow noopener noreferrer" target="_blank">' . nibblyCommentsEsc($author) . '</a>' : nibblyCommentsEsc($author)) . '</span>';
        if ($owner) $html .= '<span class="nb-comment__badge">' . nibblyCommentsEsc(nibblyPublicString('comments_owner', $lang)) . '</span>';
        $html .= '<time class="nb-comment__date" datetime="' . nibblyCommentsEsc((string)($comment['date'] ?? '')) . '">' . nibblyCommentsFormatDate((string)($comment['date'] ?? ''), $lang) . '</time>';
        $html .= '</header>';
        $html .= '<div class="nb-comment__text">' . nibblySanitizeRichHtml((string)($comment['content'] ?? '')) . '</div>';
        if ($depth < 4) $html .= '<p class="nb-comment__actions"><button type="button" class="nb-comment__reply" data-reply-to="' . nibblyCommentsEsc($id) . '" data-reply-author="' . nibblyCommentsEsc($author) . '" data-reply-label="' . nibblyCommentsEsc(nibblyPublicString('comments_replying_to', $lang)) . '">' . nibblyCommentsEsc($replyLabel) . '</button></p>';
        $html .= '</article>';
        if (!empty($comment['children'])) $html .= nibblyCommentsRenderList($comment['children'], $lang, $replyLabel, $depth + 1);
        $html .= '</li>';
    }
    return $html . '</ol>';
}

function nibblyCommentsRenderForm(array $target, string $lang, string $basePath, array $options): string {
    $s = static fn(string $key) => nibblyPublicString($key, $lang);
    $formId = 'comments';
    $token = nibblyCreateFormToken($formId);
    $question = nibblyCommentsQuestion($token);
    $settings = nibblyCommentsSettings();
    $isPage = $target['type'] === 'page';
    $title = (string)($options['formTitle'] ?? ($isPage ? $s('comments_guestbook_form_title') : $s('comments_form_title')));
    $prefix = 'nb-comment-' . substr(md5($target['key']), 0, 8);
    $html = '<div class="nb-comments__form-wrap" id="nb-comments-form">';
    $html .= '<h3 class="nb-comments__form-title" id="' . $prefix . '-title">' . nibblyCommentsEsc($title) . '</h3>';
    if (!empty($options['intro'])) $html .= '<p class="nb-comments__intro">' . nibblyCommentsEsc((string)$options['intro']) . '</p>';
    $html .= '<form class="nb-comments__form" action="' . nibblyCommentsEsc($basePath . 'api/comment-submit.php') . '" method="post" novalidate>';
    $html .= '<input type="hidden" name="lang" value="' . nibblyCommentsEsc($lang) . '">';
    $html .= '<input type="hidden" name="target_type" value="' . nibblyCommentsEsc($target['type']) . '">';
    $html .= '<input type="hidden" name="target_id" value="' . nibblyCommentsEsc($target['id']) . '">';
    $html .= '<input type="hidden" name="parent" value="">';
    $html .= '<input type="hidden" name="qcheck" value="' . nibblyCommentsEsc($question['check']) . '">';
    $html .= '<input type="hidden" name="form_id" value="' . $formId . '"><input type="hidden" name="form_token" value="' . nibblyCommentsEsc($token) . '">';
    $html .= '<div class="nb-comments__honeypot" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
    $html .= '<p class="nb-comments__replying" hidden><span class="nb-comments__replying-text"></span> <button type="button" class="nb-comments__cancel-reply">' . nibblyCommentsEsc($s('comments_cancel_reply')) . '</button></p>';
    $html .= '<div class="nb-comments__grid">';
    $html .= '<div class="form-group nb-comments__field"><label for="' . $prefix . '-author">' . nibblyCommentsEsc($s('comments_name')) . ' *</label><input type="text" id="' . $prefix . '-author" name="author" required maxlength="120" autocomplete="name"></div>';
    $html .= '<div class="form-group nb-comments__field"><label for="' . $prefix . '-email">' . nibblyCommentsEsc($s('comments_email')) . ' *</label><input type="email" id="' . $prefix . '-email" name="email" required maxlength="200" autocomplete="email"><small>' . nibblyCommentsEsc($s('comments_email_hint')) . '</small></div>';
    $html .= '<div class="form-group nb-comments__field nb-comments__field--wide"><label for="' . $prefix . '-content">' . nibblyCommentsEsc($isPage ? $s('comments_entry') : $s('comments_comment')) . ' *</label><textarea id="' . $prefix . '-content" name="content" rows="6" required maxlength="' . (int)$settings['maxLength'] . '"></textarea></div>';
    $html .= '<div class="form-group nb-comments__field nb-comments__field--check"><label for="' . $prefix . '-answer">' . nibblyCommentsEsc(str_replace('{question}', $question['question'], $s('comments_question'))) . ' *</label><input type="text" id="' . $prefix . '-answer" name="answer" inputmode="numeric" required maxlength="4" autocomplete="off"></div>';
    $html .= '</div>';
    $html .= '<p class="nb-comments__privacy">' . nibblyCommentsEsc($settings['moderation'] ? $s('comments_privacy_moderated') : $s('comments_privacy')) . '</p>';
    $html .= '<div class="form-actions"><button type="submit" class="btn btn-gradient"><span class="btn-text">' . nibblyCommentsEsc($s('comments_send')) . '</span><span class="btn-loading" style="display:none">' . nibblyCommentsEsc($s('form_sending')) . '</span></button></div>';
    $html .= '<div class="form-feedback nb-comments__feedback" role="status" aria-live="polite"></div>';
    $html .= '</form></div>';
    return $html;
}

// ---------------------------------------------------------------------------------------------
// Submission
// ---------------------------------------------------------------------------------------------

/** Heuristic spam checks. Returns '' when the text passes, else the reason. */
function nibblyCommentsSpamReason(string $author, string $email, string $content, array $settings): string {
    $text = $author . "\n" . $content;
    preg_match_all('#https?://|www\.#i', $text, $links);
    if (count($links[0]) > (int)$settings['maxLinks']) return 'links';
    if (preg_match('/\[url=|<a\s|<\/a>|\[link/i', $text)) return 'markup';
    $letters = preg_match_all('/\p{L}/u', $content);
    $latin = preg_match_all('/\p{Latin}/u', $content);
    if ($letters >= 20 && $latin / max(1, $letters) < 0.5) return 'script';
    $blocklist = nibblyCommentsBlocklist();
    if ($blocklist) {
        $haystack = mb_strtolower($text);
        foreach ($blocklist as $word) if ($word !== '' && str_contains($haystack, mb_strtolower($word))) return 'blocklist';
    }
    if (preg_match('/(.)\1{14,}/u', $content)) return 'repeat';
    return '';
}

function nibblyCommentsBlocklist(): array {
    $path = nibblyCommentsRoot() . '/content/comments-blocklist.json';
    if (!is_file($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    $words = is_array($data['words'] ?? null) ? $data['words'] : (is_array($data) ? $data : []);
    return array_values(array_filter(array_map(static fn($w) => is_string($w) ? trim($w) : '', $words), 'strlen'));
}

/** Was a name + e-mail pair approved before (any target)? */
function nibblyCommentsAuthorKnown(string $author, string $email): bool {
    $email = mb_strtolower(trim($email));
    $author = mb_strtolower(trim($author));
    if ($email === '') return false;
    foreach (nibblyCommentsAll() as $comment) {
        if (($comment['status'] ?? '') !== 'approved') continue;
        if (mb_strtolower(trim((string)($comment['email'] ?? ''))) === $email && mb_strtolower(trim((string)($comment['author'] ?? ''))) === $author) return true;
    }
    return false;
}

/** The target of a comment exists and accepts comments: [exists, title, url]. */
function nibblyCommentsTargetInfo(array $target, string $lang): array {
    $root = nibblyCommentsRoot();
    if ($target['type'] === 'news') {
        require_once $root . '/includes/news-helper.php';
        foreach (nibblyNewsAllPosts() as $post) {
            if (($post['id'] ?? '') !== $target['id'] || !empty($post['hidden'])) continue;
            $settings = nibblyCommentsSettings();
            if ($settings['closeAfterDays'] > 0 && strtotime((string)($post['date'] ?? '')) < time() - $settings['closeAfterDays'] * 86400) return [false, (string)$post['title'], ''];
            return [true, (string)($post['title'] ?? ''), nibblyNewsUrl($post, (string)($post['lang'] ?? $lang))];
        }
        return [false, '', ''];
    }
    require_once $root . '/includes/page-path.php';
    $parsed = nibblyPageParseContentKey($target['id']);
    if ($parsed === null) return [false, '', ''];
    $file = $root . '/content/pages/' . $target['id'] . '.json';
    if (!is_file($file)) return [false, '', ''];
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data) || empty($data['comments'])) return [false, (string)($data['title'] ?? ''), ''];
    $defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $url = ($parsed['lang'] !== $defaultLang ? '/' . $parsed['lang'] : '') . '/' . ($parsed['path'] === 'home' ? '' : $parsed['path']);
    return [true, (string)($data['title'] ?? ''), $url];
}

/**
 * Handle a submitted comment ($_POST). Returns ['success' => bool, 'message' => string,
 * 'status' => 'approved'|'pending'|'', 'comment' => array|null, 'formToken' => new token, 'question' => …].
 */
function nibblyCommentsSubmit(array $input, string $lang): array {
    $s = static fn(string $key) => nibblyPublicString($key, $lang);
    $settings = nibblyCommentsSettings();
    $formId = 'comments';
    $fresh = static function (bool $success, string $message, array $extra = []) use ($formId): array {
        $token = nibblyCreateFormToken($formId);
        $question = nibblyCommentsQuestion($token);
        return array_merge(['success' => $success, 'message' => $message, 'status' => '', 'comment' => null, 'formToken' => $token, 'question' => $question['question'], 'qcheck' => $question['check']], $extra);
    };
    if (!$settings['enabled']) return $fresh(false, $s('comments_closed'));
    $target = nibblyCommentsTarget((string)($input['target_type'] ?? ''), (string)($input['target_id'] ?? ''));
    if ($target === null) return $fresh(false, $s('form_not_found'));
    $clientKey = nibblyFormClientKey();
    if (!nibblyFormReserveRequest($clientKey)) return $fresh(false, $s('form_too_many'));

    // Honeypot: pretend success, count as failure
    if (trim((string)($input['website'] ?? '')) !== '') {
        nibblyFormRecordAttempt($clientKey, false);
        return $fresh(true, $s('comments_thanks_pending'), ['status' => 'pending']);
    }
    $tokenResult = nibblyValidateFormToken((string)($input['form_token'] ?? ''), $formId);
    if (empty($tokenResult['valid'])) {
        nibblyFormRecordAttempt($clientKey, false);
        return $fresh(false, $tokenResult['reason'] === 'too_fast' ? $s('comments_too_fast') : $s('form_expired'));
    }
    $token = (string)$input['form_token'];
    $answer = trim((string)($input['answer'] ?? ''));
    if ($answer === '' || !hash_equals((string)($input['qcheck'] ?? ''), nibblyCommentsSign($token, $answer))) {
        nibblyFormRecordAttempt($clientKey, false);
        return $fresh(false, $s('comments_wrong_answer'));
    }
    $author = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($input['author'] ?? ''))));
    $email = trim((string)($input['email'] ?? ''));
    $content = trim(str_replace(["\r\n", "\r"], "\n", (string)($input['content'] ?? '')));
    $parent = trim((string)($input['parent'] ?? ''));
    $errors = [];
    if ($author === '' || mb_strlen($author) > 120) $errors[] = str_replace('{field}', $s('comments_name'), $s('form_field_required'));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) $errors[] = $s('form_email_invalid');
    if (mb_strlen($content) < (int)$settings['minLength']) $errors[] = str_replace('{field}', $target['type'] === 'page' ? $s('comments_entry') : $s('comments_comment'), $s('form_field_required'));
    if (mb_strlen($content) > (int)$settings['maxLength']) $errors[] = $s('comments_too_long');
    if ($errors) {
        nibblyFormRecordAttempt($clientKey, false);
        return $fresh(false, implode(' ', $errors));
    }
    [$exists, $targetTitle, $targetUrl] = nibblyCommentsTargetInfo($target, $lang);
    if (!$exists) {
        nibblyFormRecordAttempt($clientKey, false);
        return $fresh(false, $s('comments_closed'));
    }
    $existing = nibblyCommentsLoad($target['type'], $target['id']);
    if ($parent !== '') {
        $parentOk = false;
        foreach ($existing as $c) if (($c['id'] ?? '') === $parent && ($c['status'] ?? '') === 'approved') $parentOk = true;
        if (!$parentOk) $parent = '';
    }
    // Duplicate (same author and text within the stored list)
    foreach ($existing as $c) {
        if (trim((string)($c['content_raw'] ?? '')) === $content && mb_strtolower((string)($c['email'] ?? '')) === mb_strtolower($email)) {
            nibblyFormRecordAttempt($clientKey, false);
            return $fresh(false, $s('comments_duplicate'));
        }
    }
    $spam = nibblyCommentsSpamReason($author, $email, $content, $settings);
    $status = 'pending';
    if ($spam !== '') {
        // Kept for the moderator as spam, the visitor sees the moderation message
        $status = 'spam';
    } elseif (!$settings['moderation'] || ($settings['autoApproveKnown'] && nibblyCommentsAuthorKnown($author, $email))) {
        $status = 'approved';
    }
    $contentHtml = nibblyCommentsTextToHtml($content);
    $comment = [
        'id' => 'c' . date('YmdHis') . substr(bin2hex(random_bytes(3)), 0, 5),
        'parent' => $parent,
        'author' => $author,
        'email' => $email,
        'url' => '',
        'date' => date('Y-m-d\TH:i:s'),
        'content' => $contentHtml,
        'content_raw' => $content,
        'status' => $status,
        'owner' => false,
        'source' => 'form',
        'ipHash' => hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . nibblyCommentsSecret()),
        'spamReason' => $spam,
    ];
    $saved = nibblyCommentsUpdate($target['type'], $target['id'], static function (array &$comments) use ($comment): bool {
        $comments[] = $comment;
        return true;
    });
    if (!$saved) {
        nibblyFormRecordAttempt($clientKey, false);
        return $fresh(false, $s('form_save_failed'));
    }
    nibblyFormRecordAttempt($clientKey, true);
    if ($settings['notify'] && $status !== 'spam') nibblyCommentsNotify($comment, $target, $targetTitle, $targetUrl);
    $public = $comment;
    unset($public['email'], $public['ipHash'], $public['content_raw'], $public['spamReason']);
    return $fresh(true, $status === 'approved' ? $s('comments_thanks_published') : $s('comments_thanks_pending'), ['status' => $status === 'approved' ? 'approved' : 'pending', 'comment' => $public, 'html' => $status === 'approved' ? nibblyCommentsRenderList([$public + ['children' => []]], $lang, $s('comments_reply'), 1) : '']);
}

/** Plain text of a visitor → paragraphs with line breaks; URLs become nofollow links. */
function nibblyCommentsTextToHtml(string $text): string {
    $paragraphs = preg_split('/\n{2,}/', trim($text)) ?: [];
    $html = '';
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') continue;
        $escaped = nl2br(htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        $escaped = preg_replace_callback('#(?<![">])(https?://[^\s<]+[^\s<.,;:!?)])#', static fn($m) => '<a href="' . $m[1] . '" rel="nofollow noopener noreferrer" target="_blank">' . $m[1] . '</a>', $escaped);
        $html .= '<p>' . $escaped . '</p>' . "\n";
    }
    return $html;
}

/** E-mail to the site's recipient about a new entry (uses the forms' mail settings). */
function nibblyCommentsNotify(array $comment, array $target, string $targetTitle, string $targetUrl): bool {
    require_once __DIR__ . '/forms.php';
    $settings = nibblyFormsLoadEmailSettings();
    if (($settings['method'] ?? 'inactive') === 'inactive') return false;
    $siteUrl = '';
    $seo = __DIR__ . '/seo-helper.php';
    if (is_file($seo)) { require_once $seo; if (function_exists('nibblySeoBaseUrl')) $siteUrl = rtrim(nibblySeoBaseUrl(), '/'); }
    $kind = $target['type'] === 'page' ? 'Gästebucheintrag' : 'Kommentar';
    $subject = 'Neuer ' . $kind . ($comment['status'] === 'approved' ? ' (veröffentlicht)' : ' (wartet auf Freigabe)') . ': ' . $targetTitle;
    $body = $kind . " von " . $comment['author'] . " <" . $comment['email'] . ">\n"
        . 'Zu: ' . $targetTitle . ' ' . $siteUrl . $targetUrl . "\n"
        . 'Datum: ' . $comment['date'] . "\n"
        . 'Status: ' . $comment['status'] . "\n\n"
        . $comment['content_raw'] . "\n\n"
        . 'Freigeben oder löschen: ' . $siteUrl . '/admin/dashboard.php#comments' . "\n";
    $pseudoForm = ['id' => 'comments', 'label' => $kind, 'fields' => []];
    return nibblyFormsSendNotification($pseudoForm, ['email' => $comment['email'], 'name' => $comment['author']], $subject, $body);
}
