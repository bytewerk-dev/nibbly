<?php
/**
 * Comments and guestbook module (includes/comments.php): storage and tree, moderation, spam
 * heuristics, token/question/honeypot checks of a submission, rendering, imported archive format.
 * Runs against a disposable copy of content/ inside the suite copy (never the live content).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/nibbly-comments-' . bin2hex(random_bytes(4));
mkdir($work . '/content/comments', 0755, true);
mkdir($work . '/content/news', 0755, true);
mkdir($work . '/content/pages', 0755, true);
mkdir($work . '/includes', 0755, true);
foreach (['comments', 'json-store', 'form-protection', 'public-strings', 'html-sanitizer', 'news-helper', 'page-path', 'forms'] as $name) {
    copy($root . '/includes/' . $name . '.php', $work . '/includes/' . $name . '.php');
}
if (!is_dir($work . '/admin')) mkdir($work . '/admin');
file_put_contents($work . '/content/settings.json', json_encode(['comments' => ['notify' => false], 'email' => ['method' => 'inactive']]));
file_put_contents($work . '/content/news/2026-01-02-test-beitrag.json', json_encode(['id' => '2026-01-02-test-beitrag', 'slug' => 'test-beitrag', 'lang' => 'de', 'title' => 'Testbeitrag', 'date' => '2026-01-02', 'content' => '<p>x</p>', 'hidden' => false]));
file_put_contents($work . '/content/pages/de_start__gaestebuch.json', json_encode(['page' => 'de_start__gaestebuch', 'lang' => 'de', 'title' => 'Gästebuch', 'comments' => 'guestbook', 'sections' => []]));
file_put_contents($work . '/content/pages/de_privates.json', json_encode(['page' => 'de_privates', 'lang' => 'de', 'title' => 'Privates', 'sections' => []]));
define('SITE_LANG_DEFAULT', 'de');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'comments-smoke';
require_once $work . '/includes/comments.php';

$failures = 0;
function commentsAssert(bool $ok, string $message): void {
    global $failures;
    if (!$ok) { $failures++; fwrite(STDERR, "FAIL: $message\n"); }
}

// Imported archive: file name = post id, tree with replies, owner flag
file_put_contents($work . '/content/comments/2026-01-02-test-beitrag.json', json_encode(['target' => 'news:2026-01-02-test-beitrag', 'comments' => [
    ['id' => 'wp1', 'parent' => '', 'author' => 'Anna', 'email' => 'anna@example.org', 'date' => '2020-01-01T10:00:00', 'content' => '<p>Erster</p>', 'status' => 'approved', 'owner' => false, 'source' => 'wordpress'],
    ['id' => 'wp2', 'parent' => 'wp1', 'author' => 'Site Owner', 'email' => 'owner@example.org', 'date' => '2020-01-02T10:00:00', 'content' => '<p>Antwort</p>', 'status' => 'approved', 'owner' => true, 'source' => 'wordpress'],
    ['id' => 'wp3', 'parent' => '', 'author' => 'Spam', 'email' => 's@example.org', 'date' => '2020-01-03T10:00:00', 'content' => '<p>Hidden</p>', 'status' => 'pending', 'owner' => false, 'source' => 'form'],
]]));
$tree = nibblyCommentsTree(nibblyCommentsLoad('news', '2026-01-02-test-beitrag'));
commentsAssert(count($tree) === 1 && count($tree[0]['children']) === 1 && $tree[0]['children'][0]['owner'] === true, 'tree: one root with the owner reply, pending left out');
commentsAssert(nibblyCommentsCountPending() === 1, 'one pending comment');
commentsAssert(nibblyCommentsApprovedCount('news', '2026-01-02-test-beitrag') === 2, 'two approved');

$GLOBALS['currentLang'] = 'de';
$html = nibblyCommentsRender('news', '2026-01-02-test-beitrag', ['lang' => 'de', 'basePath' => '../../../']);
commentsAssert(str_contains($html, 'id="comment-wp1"') && str_contains($html, 'nb-comment--owner') && !str_contains($html, 'Hidden'), 'render: approved comments, owner badge, pending hidden');
commentsAssert(str_contains($html, 'name="form_token"') && str_contains($html, 'name="qcheck"') && str_contains($html, 'name="website"') && str_contains($html, 'Was ergibt'), 'render: token, signed question, honeypot');
commentsAssert(!str_contains($html, 'anna@example.org'), 'render: e-mail addresses never shown');
commentsAssert(str_contains($html, '../../../js/comments.js') && str_contains($html, 'action="../../../api/comment-submit.php"'), 'render: script and endpoint relative to basePath');
commentsAssert(preg_match('/data-reply-to="wp2"/', $html) === 1, 'render: reply button on the nested comment');

// Submission: honeypot pretends success, wrong answer and too-fast token are rejected, a valid one is pending
preg_match('/name="form_token" value="([^"]+)"/', $html, $tokenMatch);
preg_match('/name="qcheck" value="([^"]+)"/', $html, $checkMatch);
preg_match('/Was ergibt (\d+ \+ \d+)\?/', $html, $q);
// Every request is built from the form it answers (token, signed question, its correct answer), so no case
// inherits the answer of an earlier question. Overrides go through array_replace: with "+" the left value wins.
$fields = ['target_type' => 'news', 'target_id' => '2026-01-02-test-beitrag', 'author' => 'Berta', 'email' => 'berta@example.org', 'content' => "Ein freundlicher Kommentar.\n\nMit zwei Absätzen.", 'parent' => 'wp1'];
$sumOf = static fn(string $question): string => (string)array_sum(array_map('intval', explode('+', $question)));
$formFrom = static fn(array $result): array => ['token' => $result['formToken'], 'qcheck' => $result['qcheck'], 'answer' => $sumOf($result['question'])];
$request = static fn(array $form, array $overrides = []): array => array_replace($fields, ['form_token' => $form['token'], 'qcheck' => $form['qcheck'], 'answer' => $form['answer']], $overrides);
$wrongAnswer = '99'; // never the sum of two digits 1-9
$stillThree = static fn(): bool => count(nibblyCommentsLoad('news', '2026-01-02-test-beitrag')) === 3;
$form = ['token' => $tokenMatch[1], 'qcheck' => $checkMatch[1], 'answer' => $sumOf($q[1])];

$result = nibblyCommentsSubmit($request($form, ['website' => 'http://spam.invalid']), 'de');
commentsAssert($result['success'] && $result['status'] === 'pending' && $stillThree(), 'honeypot: pretended success, nothing stored');

$result = nibblyCommentsSubmit($request($form), 'de');
commentsAssert(!$result['success'] && str_contains($result['message'], 'schnell'), 'token younger than the minimum age is rejected: ' . $result['message']);
// Every answer carries a fresh token and question (the token was consumed); age it past the minimum
$ageTokens = static function () use ($work): void {
    $tokens = json_decode((string)file_get_contents($work . '/content/form-tokens.json'), true);
    foreach ($tokens as &$entry) $entry['created'] -= 10;
    unset($entry);
    file_put_contents($work . '/content/form-tokens.json', json_encode($tokens));
};
$form = $formFrom($result);
$ageTokens();
$result = nibblyCommentsSubmit($request($form, ['answer' => $wrongAnswer]), 'de');
commentsAssert(!$result['success'] && str_contains($result['message'], 'Rechenfrage') && $stillThree(), 'wrong answer rejected: ' . $result['message']);

// Regression (CI flake in 2.7.0): the next question has the same sum as the previous one. The random
// questions collide in about 7 % of runs, so the collision is forced here with a token signed for that sum.
$previousAnswer = $form['answer'];
$sameSumToken = nibblyCreateFormToken('comments');
$form = ['token' => $sameSumToken, 'qcheck' => nibblyCommentsSign($sameSumToken, $previousAnswer), 'answer' => $previousAnswer];
commentsAssert($request($form, ['answer' => $wrongAnswer])['answer'] === $wrongAnswer, 'same sum: the override replaces the answer of the form');
$ageTokens();
$result = nibblyCommentsSubmit($request($form, ['answer' => $wrongAnswer]), 'de');
commentsAssert(!$result['success'] && str_contains($result['message'], 'Rechenfrage') && $stillThree(), 'same sum: wrong answer rejected: ' . $result['message']);

// The token was consumed; answer the fresh question with its own correct answer
$form = $formFrom($result);
$ageTokens();
$result = nibblyCommentsSubmit($request($form), 'de');
commentsAssert($result['success'] && $result['status'] === 'pending' && count(nibblyCommentsLoad('news', '2026-01-02-test-beitrag')) === 4, 'valid submission is pending: ' . $result['message']);
$stored = nibblyCommentsLoad('news', '2026-01-02-test-beitrag');
$new = end($stored);
commentsAssert($new['author'] === 'Berta' && $new['parent'] === 'wp1' && $new['status'] === 'pending' && str_contains($new['content'], '<p>Ein freundlicher Kommentar.</p>') && isset($new['content_raw']), 'stored comment: parent, status, paragraphs');
commentsAssert(nibblyCommentsCountPending() === 2, 'pending count grew');

// Moderation
commentsAssert(nibblyCommentsSetStatus('news', '2026-01-02-test-beitrag', $new['id'], 'approved') && nibblyCommentsApprovedCount('news', '2026-01-02-test-beitrag') === 3, 'approve');
commentsAssert(nibblyCommentsAuthorKnown('Berta', 'BERTA@example.org'), 'approved author is known (case-insensitive e-mail)');
commentsAssert(nibblyCommentsDelete('news', '2026-01-02-test-beitrag', 'wp1'), 'delete');
$after = nibblyCommentsLoad('news', '2026-01-02-test-beitrag');
$byId = array_column($after, null, 'id');
commentsAssert(!isset($byId['wp1']) && $byId['wp2']['parent'] === '' && $byId[$new['id']]['parent'] === '', 'replies of a deleted comment move up');
commentsAssert(!nibblyCommentsSetStatus('news', '2026-01-02-test-beitrag', 'missing', 'approved'), 'unknown comment id');

// Spam heuristics
$settings = nibblyCommentsSettings();
commentsAssert(nibblyCommentsSpamReason('A', 'a@b.c', 'Buy http://a.invalid http://b.invalid http://c.invalid', $settings) === 'links', 'too many links');
commentsAssert(nibblyCommentsSpamReason('A', 'a@b.c', '<a href="x">y</a>', $settings) === 'markup', 'html links');
commentsAssert(nibblyCommentsSpamReason('A', 'a@b.c', 'Привет это длинный текст без латиницы вообще совсем', $settings) === 'script', 'non-Latin script');
commentsAssert(nibblyCommentsSpamReason('A', 'a@b.c', 'Ein ganz normaler Text mit einem Link https://example.org dazu.', $settings) === '', 'normal text passes');
file_put_contents($work . '/content/comments-blocklist.json', json_encode(['words' => ['casino']]));
commentsAssert(nibblyCommentsSpamReason('A', 'a@b.c', 'Best Casino offers', $settings) === 'blocklist', 'blocklist word');

// Page target (guestbook) needs the "comments" field, other pages take no entries
[$exists] = nibblyCommentsTargetInfo(nibblyCommentsTarget('page', 'de_start__gaestebuch'), 'de');
[$other] = nibblyCommentsTargetInfo(nibblyCommentsTarget('page', 'de_privates'), 'de');
[$missing] = nibblyCommentsTargetInfo(nibblyCommentsTarget('news', 'gibt-es-nicht'), 'de');
commentsAssert($exists && !$other && !$missing, 'targets: guestbook page yes, plain page no, unknown post no');
commentsAssert(nibblyCommentsTarget('news', '../etc') === null && nibblyCommentsTarget('page', 'a b') === null, 'invalid target ids');
commentsAssert(str_contains(nibblyCommentsTextToHtml("Zeile 1\nZeile 2\n\nhttps://example.org/x"), '<br>') && str_contains(nibblyCommentsTextToHtml('siehe https://example.org/x.'), 'rel="nofollow noopener noreferrer"'), 'text to html');

// Cleanup
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
}
rmdir($work);

if ($failures > 0) exit(1);
echo "PASS comments-smoke: storage, tree, rendering, protection (token, question, honeypot), moderation, spam heuristics, targets\n";
