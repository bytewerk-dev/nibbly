<?php
/**
 * Dashboard API of the newsletter module (includes/newsletter.php).
 * Editors manage subscribers, lists and campaigns; delivery settings are for admins.
 */
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }
require_once NIBBLY_ADMIN_DIR . '/../includes/newsletter.php';

if (!nibblyNewsletterModuleEnabled()) { http_response_code(403); jsonResponse(false, null, 'Forbidden'); }

$fail = static function (string $key, int $status = 422): void {
    http_response_code($status);
    jsonResponse(false, null, t($key));
};
$json = static function (string $field): array {
    $value = json_decode((string)($_POST[$field] ?? ''), true);
    return is_array($value) ? $value : [];
};
$summary = static function (array $campaign): array {
    return array_intersect_key($campaign, array_flip(['id', 'subject', 'status', 'lists', 'sendAt', 'createdAt', 'updatedAt', 'startedAt', 'finishedAt', 'stats', 'lastError']));
};
$overview = static function () use ($summary): array {
    $settings = nibblyNewsletterSettings();
    $subscribers = nibblyNewsletterSubscribers();
    $counts = ['subscribed' => 0, 'pending' => 0, 'unsubscribed' => 0, 'bounced' => 0];
    $perList = [];
    foreach ($subscribers as $subscriber) {
        $status = $subscriber['status'] ?? '';
        if (isset($counts[$status])) $counts[$status]++;
        if ($status === 'subscribed') foreach ($subscriber['lists'] ?? [] as $list) $perList[$list] = ($perList[$list] ?? 0) + 1;
    }
    $transport = nibblyNewsletterTransport($settings);
    $state = nibblyNewsletterRead('state.json');
    $data = [
        'lists' => array_map(static fn($list) => $list + ['count' => $perList[$list['id']] ?? 0], nibblyNewsletterLists()),
        'campaigns' => array_map($summary, nibblyNewsletterCampaigns()),
        'counts' => $counts,
        'fields' => $settings['fields'],
        'ready' => $transport['ok'], 'readyError' => $transport['ok'] ? '' : t($transport['error']),
        'sender' => $transport['fromEmail'],
        'sent24h' => nibblyNewsletterSentLast24h(), 'dailyLimit' => $settings['dailyLimit'],
        'lastRun' => $state['lastRun'] ?? null,
        'isAdmin' => isAdmin(),
    ];
    if (isAdmin()) {
        $base = nibblyNewsletterBaseUrl();
        $data['settings'] = nibblyNewsletterPublicSettings($settings);
        $data['cronUrl'] = $base !== '' ? nibblyNewsletterUrl('cron', ['token' => nibblyNewsletterCronToken()]) : '';
        $data['cronCommand'] = 'php ' . realpath(NIBBLY_ADMIN_DIR . '/../cli/newsletter.php');
        $data['siteMail'] = (nibblyNewsletterSiteSettings()['email']['method'] ?? 'inactive') !== 'inactive';
    }
    return $data;
};
$campaignDetail = static function (array $campaign): array {
    $data = ['campaign' => $campaign];
    if (!in_array($campaign['status'], ['draft', 'scheduled'], true)) {
        $rcpt = nibblyNewsletterRead('campaigns/' . $campaign['id'] . '.rcpt.json');
        $subscribers = nibblyNewsletterSubscribers();
        $rows = [];
        foreach ((array)($rcpt['done'] ?? []) as $sid => $entry) {
            $rows[] = ['email' => $subscribers[$sid]['email'] ?? '—', 'sent' => $entry['t'] ?? 0, 'opened' => $entry['o'] ?? 0,
                'clicks' => $entry['c'] ?? 0, 'unsubscribed' => $entry['u'] ?? 0, 'error' => $entry['e'] ?? ''];
        }
        $links = (array)($rcpt['links'] ?? []);
        arsort($links);
        $data['report'] = ['queued' => count((array)($rcpt['queue'] ?? [])), 'recipients' => array_slice($rows, 0, 2000),
            'links' => array_map(static fn($url, $count) => ['url' => $url, 'count' => $count], array_keys($links), $links)];
    } else {
        $data['recipientCount'] = count(nibblyNewsletterRecipients($campaign));
    }
    return $data;
};
$requireCampaign = static function () use ($fail): array {
    $campaign = nibblyNewsletterCampaign((string)($_POST['id'] ?? $_GET['id'] ?? ''));
    if (!$campaign) $fail('newsletter.error_not_found', 404);
    return $campaign;
};

try {
    switch ($action) {
        case 'load-newsletter':
            jsonResponse(true, $overview());

        case 'load-newsletter-subscribers':
            $rows = [];
            foreach (nibblyNewsletterSubscribers() as $subscriber) {
                $rows[] = array_intersect_key($subscriber, array_flip(['id', 'email', 'firstName', 'lastName', 'status', 'lists', 'tags', 'fields', 'source', 'createdAt', 'confirmedAt', 'unsubscribedAt', 'lang']));
            }
            jsonResponse(true, ['subscribers' => $rows]);

        case 'load-newsletter-log':
            jsonResponse(true, ['entries' => nibblyNewsletterLogEntries(), 'enabled' => nibblyNewsletterSettings()['mailLog'], 'days' => NIBBLY_NL_LOG_DAYS]);

        case 'newsletter-clear-log':
            if (!isAdmin()) { http_response_code(403); jsonResponse(false, null, 'Forbidden'); }
            nibblyNewsletterClearLog();
            jsonResponse(true, ['entries' => []]);

        case 'load-newsletter-campaign':
            jsonResponse(true, $campaignDetail($requireCampaign()));

        case 'load-newsletter-posts':
            require_once NIBBLY_ADMIN_DIR . '/../includes/news-helper.php';
            $posts = [];
            foreach (nibblyNewsAllPosts(true) as $post) {
                if (!empty($post['hidden']) || empty($post['title'])) continue;
                $posts[] = ['title' => (string)$post['title'], 'date' => (string)($post['date'] ?? ''), 'url' => nibblyNewsUrl($post),
                    'excerpt' => trim(html_entity_decode(strip_tags((string)($post['excerpt'] ?? '')), ENT_QUOTES, 'UTF-8')), 'image' => (string)($post['image'] ?? '')];
            }
            usort($posts, static fn($a, $b) => strcmp($b['date'], $a['date']));
            jsonResponse(true, ['posts' => array_slice($posts, 0, 50)]);

        case 'get-newsletter-export':
            $status = (string)($_GET['status'] ?? '');
            $list = (string)($_GET['list'] ?? '');
            $settings = nibblyNewsletterSettings();
            $listNames = array_column(nibblyNewsletterLists(), 'name', 'id');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="newsletter-subscribers-' . date('Y-m-d') . '.csv"');
            header('Cache-Control: no-store');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            // Cells starting with = + - @ would run as formulas in spreadsheets.
            $safe = static fn($value): string => preg_match('/^[=+\-@\t\r]/', (string)$value) ? "'" . $value : (string)$value;
            fputcsv($out, array_merge(['email', 'first_name', 'last_name', 'status', 'lists', 'tags', 'created', 'confirmed'], array_column($settings['fields'], 'key')), ';', '"', '');
            foreach (nibblyNewsletterSubscribers() as $subscriber) {
                if ($status !== '' && ($subscriber['status'] ?? '') !== $status) continue;
                if ($list !== '' && !in_array($list, $subscriber['lists'] ?? [], true)) continue;
                $row = [$subscriber['email'], $subscriber['firstName'] ?? '', $subscriber['lastName'] ?? '', $subscriber['status'] ?? '',
                    implode(', ', array_map(static fn($id) => $listNames[$id] ?? '', $subscriber['lists'] ?? [])), implode(', ', $subscriber['tags'] ?? []),
                    $subscriber['createdAt'] ?? '', $subscriber['confirmedAt'] ?? ''];
                foreach ($settings['fields'] as $field) $row[] = $subscriber['fields'][$field['key']] ?? '';
                fputcsv($out, array_map($safe, $row), ';', '"', '');
            }
            exit;

        case 'newsletter-save-settings':
            if (!isAdmin()) { http_response_code(403); jsonResponse(false, null, 'Forbidden'); }
            nibblyNewsletterSaveSettings($json('settings'));
            jsonResponse(true, $overview());

        case 'newsletter-save-list':
            $id = (string)($_POST['id'] ?? '');
            $name = mb_substr(trim(preg_replace('/[\x00-\x1f]+/', ' ', (string)($_POST['name'] ?? ''))), 0, 80);
            $description = mb_substr(trim(preg_replace('/[\x00-\x1f]+/', ' ', (string)($_POST['description'] ?? ''))), 0, 200);
            if ($name === '') $fail('newsletter.error_name');
            nibblyNewsletterUpdate('lists.json', function (array &$store) use ($id, $name, $description) {
                $store['lists'] = array_values(array_filter((array)($store['lists'] ?? []), 'is_array'));
                foreach ($store['lists'] as &$list) {
                    if ($list['id'] === $id) { $list['name'] = $name; $list['description'] = $description; return true; }
                }
                if ($id !== '') return false;
                $store['lists'][] = ['id' => 'list_' . bin2hex(random_bytes(5)), 'name' => $name, 'description' => $description];
                return true;
            }, ['lists' => []]);
            jsonResponse(true, $overview());

        case 'newsletter-delete-list':
            $id = (string)($_POST['id'] ?? '');
            nibblyNewsletterUpdate('lists.json', function (array &$store) use ($id): void {
                $store['lists'] = array_values(array_filter((array)($store['lists'] ?? []), static fn($list) => is_array($list) && $list['id'] !== $id));
            }, ['lists' => []]);
            nibblyNewsletterUpdate('subscribers.json', function (array &$store) use ($id): void {
                foreach ((array)($store['subscribers'] ?? []) as $sid => $subscriber) {
                    $store['subscribers'][$sid]['lists'] = array_values(array_diff($subscriber['lists'] ?? [], [$id]));
                }
            }, ['subscribers' => []]);
            jsonResponse(true, $overview());

        case 'newsletter-save-subscriber':
            $input = $json('subscriber');
            $id = (string)($input['id'] ?? '');
            $email = nibblyNewsletterNormalizeEmail((string)($input['email'] ?? ''));
            if ($email === '') $fail('newsletter.error_email');
            $settings = nibblyNewsletterSettings();
            $clean = nibblyNewsletterCleanSubscriber($input, $settings);
            $status = in_array($input['status'] ?? '', ['subscribed', 'pending', 'unsubscribed', 'bounced'], true) ? $input['status'] : 'subscribed';
            $error = '';
            nibblyNewsletterUpdate('subscribers.json', function (array &$store) use ($id, $email, $clean, $status, &$error) {
                $store['subscribers'] = (array)($store['subscribers'] ?? []);
                $other = nibblyNewsletterFindByEmail($store['subscribers'], $email);
                if ($other !== null && $other !== $id) { $error = 'newsletter.error_exists'; return false; }
                if ($id !== '' && !isset($store['subscribers'][$id])) { $error = 'newsletter.error_not_found'; return false; }
                $subscriber = $id !== '' ? $store['subscribers'][$id] : nibblyNewsletterNewSubscriber($email, $status, 'admin');
                $subscriber = array_merge($subscriber, $clean, ['email' => $email]);
                if ($subscriber['status'] !== $status) {
                    $subscriber['status'] = $status;
                    if ($status === 'subscribed') { $subscriber['confirmedAt'] = $subscriber['confirmedAt'] ?? date('c'); $subscriber['unsubscribedAt'] = null; }
                    if ($status === 'unsubscribed') $subscriber['unsubscribedAt'] = date('c');
                }
                $store['subscribers'][$subscriber['id']] = $subscriber;
                return true;
            }, ['subscribers' => []]);
            if ($error !== '') $fail($error);
            jsonResponse(true, $overview());

        case 'newsletter-delete-subscribers':
            $ids = array_flip(array_map('strval', $json('ids')));
            nibblyNewsletterUpdate('subscribers.json', function (array &$store) use ($ids): void {
                $store['subscribers'] = array_diff_key((array)($store['subscribers'] ?? []), $ids);
            }, ['subscribers' => []]);
            jsonResponse(true, $overview());

        case 'newsletter-resend-confirm':
            $subscriber = nibblyNewsletterSubscribers()[(string)($_POST['id'] ?? '')] ?? null;
            if (!$subscriber || $subscriber['status'] !== 'pending') $fail('newsletter.error_not_found', 404);
            $error = nibblyNewsletterSendConfirmation($subscriber);
            if ($error !== '') { http_response_code(502); jsonResponse(false, null, str_starts_with($error, 'newsletter.') ? t($error) : t('newsletter.error_send', ['message' => $error])); }
            jsonResponse(true, null);

        case 'newsletter-import':
            $csv = (string)($_POST['csv'] ?? '');
            if (strlen($csv) > 5 * 1024 * 1024) $fail('newsletter.error_import_size');
            $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
            if (!mb_check_encoding($csv, 'UTF-8')) $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
            $lines = preg_split('/\r\n|\r|\n/', trim($csv));
            $first = $lines[0] ?? '';
            $delimiter = ';';
            foreach ([';', ',', "\t"] as $candidate) if (substr_count($first, $candidate) > substr_count($first, $delimiter)) $delimiter = $candidate;
            $settings = nibblyNewsletterSettings();
            $aliases = ['email' => ['email', 'e-mail', 'mail', 'e-mail-adresse', 'email address', 'emailadresse'],
                'firstName' => ['first_name', 'firstname', 'first name', 'vorname', 'given name'],
                'lastName' => ['last_name', 'lastname', 'last name', 'nachname', 'surname', 'family name'], 'tags' => ['tags', 'schlagwörter']];
            $header = array_map(static fn($cell) => mb_strtolower(trim((string)$cell)), str_getcsv($first, $delimiter, '"', ''));
            $columns = [];
            foreach ($header as $index => $cell) {
                foreach ($aliases as $key => $names) if (in_array($cell, $names, true)) $columns[$key] = $index;
                foreach ($settings['fields'] as $field) if ($cell === $field['key'] || $cell === mb_strtolower($field['label'])) $columns['field:' . $field['key']] = $index;
            }
            // Without a header row: the first column that holds an address.
            $hasHeader = isset($columns['email']);
            if (!$hasHeader) {
                foreach ($header as $index => $cell) if (nibblyNewsletterNormalizeEmail($cell) !== '') { $columns = ['email' => $index]; break; }
                if (!isset($columns['email'])) $fail('newsletter.error_import_email');
            }
            $lists = array_values(array_intersect(array_map('strval', $json('lists')), array_column(nibblyNewsletterLists(), 'id')));
            $update = ($_POST['existing'] ?? 'skip') === 'update';
            $result = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'invalid' => 0];
            nibblyNewsletterUpdate('subscribers.json', function (array &$store) use ($lines, $hasHeader, $delimiter, $columns, $lists, $update, &$result): void {
                $store['subscribers'] = (array)($store['subscribers'] ?? []);
                $byEmail = [];
                foreach ($store['subscribers'] as $id => $subscriber) $byEmail[$subscriber['email'] ?? ''] = $id;
                foreach (array_slice($lines, $hasHeader ? 1 : 0) as $line) {
                    if (trim($line) === '') continue;
                    $cells = str_getcsv($line, $delimiter, '"', '');
                    $cell = static fn(string $key): string => mb_substr(trim(preg_replace('/[\x00-\x1f]+/', ' ', (string)($cells[$columns[$key] ?? -1] ?? ''))), 0, 300);
                    $email = nibblyNewsletterNormalizeEmail($cell('email'));
                    if ($email === '') { $result['invalid']++; continue; }
                    $existing = $byEmail[$email] ?? null;
                    if ($existing !== null && !$update) { $result['skipped']++; continue; }
                    $subscriber = $existing !== null ? $store['subscribers'][$existing] : nibblyNewsletterNewSubscriber($email, 'subscribed', 'import');
                    foreach (['firstName', 'lastName'] as $key) if ($cell($key) !== '') $subscriber[$key] = mb_substr($cell($key), 0, 120);
                    if ($cell('tags') !== '') $subscriber['tags'] = array_values(array_unique(array_merge($subscriber['tags'] ?? [], array_filter(array_map('trim', explode(',', $cell('tags')))))));
                    foreach ($columns as $key => $index) if (str_starts_with((string)$key, 'field:') && $cell($key) !== '') $subscriber['fields'][substr($key, 6)] = $cell($key);
                    $subscriber['lists'] = array_values(array_unique(array_merge($subscriber['lists'] ?? [], $lists)));
                    $store['subscribers'][$subscriber['id']] = $subscriber;
                    $byEmail[$email] = $subscriber['id'];
                    $result[$existing !== null ? 'updated' : 'added']++;
                }
            }, ['subscribers' => []]);
            jsonResponse(true, ['result' => $result] + $overview());

        case 'newsletter-save-campaign':
            $campaign = nibblyNewsletterSaveCampaign($json('campaign'), (string)($_POST['id'] ?? ''));
            jsonResponse(true, $campaignDetail($campaign));

        case 'newsletter-duplicate-campaign':
            $source = $requireCampaign();
            $copy = nibblyNewsletterSaveCampaign(['subject' => $source['subject'], 'preheader' => $source['preheader'] ?? '', 'lists' => $source['lists'] ?? [], 'blocks' => $source['blocks'] ?? []]);
            jsonResponse(true, ['id' => $copy['id']] + $overview());

        case 'newsletter-delete-campaign':
            $campaign = $requireCampaign();
            if ($campaign['status'] === 'sending') $fail('newsletter.error_locked');
            nibblyNewsletterDeleteCampaign($campaign['id']);
            jsonResponse(true, $overview());

        case 'newsletter-preview':
            $input = $json('campaign');
            $campaign = ['id' => '', 'subject' => (string)($input['subject'] ?? ''), 'preheader' => (string)($input['preheader'] ?? ''),
                'blocks' => nibblyNewsletterCleanBlocks($input['blocks'] ?? [])];
            $sample = ['firstName' => t('newsletter.sample_first'), 'lastName' => t('newsletter.sample_last'), 'email' => 'name@example.com', 'fields' => []];
            $mail = nibblyNewsletterRender($campaign, $sample, ['preview' => true]);
            jsonResponse(true, ['html' => $mail['html'], 'subject' => $mail['subject'], 'text' => $mail['text']]);

        case 'newsletter-test':
            $campaign = $requireCampaign();
            $email = nibblyNewsletterNormalizeEmail((string)($_POST['email'] ?? ''));
            if ($email === '') $fail('newsletter.error_email');
            $transport = nibblyNewsletterTransport();
            if (!$transport['ok']) $fail($transport['error']);
            $known = nibblyNewsletterSubscribers();
            $sid = nibblyNewsletterFindByEmail($known, $email);
            $sample = $sid !== null ? $known[$sid] : ['firstName' => t('newsletter.sample_first'), 'lastName' => t('newsletter.sample_last'), 'email' => $email, 'fields' => []];
            $mail = nibblyNewsletterRender($campaign, $sample, ['preview' => true]);
            $mail['subject'] = '[Test] ' . $mail['subject'];
            $error = nibblyNewsletterDeliver($transport, $email, $mail, 'test', $campaign['id']);
            if ($error !== '') { http_response_code(502); jsonResponse(false, null, t('newsletter.error_send', ['message' => $error])); }
            nibblyNewsletterNoteSent(1);
            jsonResponse(true, null);

        case 'newsletter-start':
            $campaign = $requireCampaign();
            $sendAt = trim((string)($_POST['sendAt'] ?? ''));
            $time = $sendAt !== '' ? strtotime($sendAt) : null;
            if ($sendAt !== '' && $time === false) $fail('newsletter.error_date');
            $campaign = nibblyNewsletterStartCampaign($campaign['id'], $time ?: null);
            jsonResponse(true, $campaignDetail($campaign));

        case 'newsletter-unschedule':
        case 'newsletter-pause':
        case 'newsletter-resume':
            $campaign = $requireCampaign();
            $from = ['newsletter-unschedule' => 'scheduled', 'newsletter-pause' => 'sending', 'newsletter-resume' => 'paused'][$action];
            $to = ['newsletter-unschedule' => 'draft', 'newsletter-pause' => 'paused', 'newsletter-resume' => 'sending'][$action];
            if ($campaign['status'] !== $from) $fail('newsletter.error_locked');
            $campaign = nibblyNewsletterUpdateCampaign($campaign['id'], function (array &$stored) use ($to): void {
                $stored['status'] = $to;
                $stored['lastError'] = '';
                if ($to === 'draft') $stored['sendAt'] = null;
                $stored['updatedAt'] = date('c');
            });
            jsonResponse(true, $campaignDetail($campaign));

        case 'newsletter-run':
            // The dashboard keeps a campaign going while it is open; a cron does the same without it.
            session_write_close();
            jsonResponse(true, ['run' => nibblyNewsletterRunQueue()]);
    }
} catch (InvalidArgumentException $error) {
    $fail($error->getMessage());
} catch (RuntimeException $error) {
    $fail($error->getMessage(), 503);
}
http_response_code(404);
jsonResponse(false, null, 'Unknown action');
