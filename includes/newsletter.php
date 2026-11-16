<?php
/**
 * Newsletter module: subscribers, lists, campaigns, rendering and the send queue.
 *
 * Storage (content/newsletter/, never public):
 *   settings.json            sender, delivery, limits, design, cron token
 *   subscribers.json         {"subscribers": {id: {...}}}
 *   lists.json               {"lists": [{id, name, description}]}
 *   campaigns/{id}.json      one campaign with its blocks and counters
 *   campaigns/{id}.rcpt.json queue and per-recipient results of a campaign
 *   state.json               hourly send counters (daily limit), last run
 *   mail-log.jsonl           one line per outgoing mail (newest entries only)
 *   secret.json              HMAC key for tracking and view links
 *
 * Public links go through api/newsletter.php; the dashboard API is admin/api/newsletter.php;
 * cron: cli/newsletter.php or api/newsletter.php?a=cron&token=...
 */

require_once __DIR__ . '/json-store.php';
require_once __DIR__ . '/html-sanitizer.php';
require_once __DIR__ . '/site-url.php';

const NIBBLY_NL_MAX_ATTEMPTS = 3;
const NIBBLY_NL_LOG_ENTRIES = 2000;
const NIBBLY_NL_LOG_DAYS = 30;

function nibblyNewsletterDir(): string {
    return dirname(__DIR__) . '/content/newsletter';
}

function nibblyNewsletterPath(string $name): string {
    return nibblyNewsletterDir() . '/' . $name;
}

function nibblyNewsletterRead(string $name, array $default = []): array {
    $path = nibblyNewsletterPath($name);
    if (!is_file($path)) return $default;
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : $default;
}

/** Locked read-modify-write; the callback returns false to abort. */
function nibblyNewsletterUpdate(string $name, callable $update, array $default = []): bool {
    return nibblyJsonUpdate(nibblyNewsletterPath($name), $update, $default);
}

function nibblyNewsletterModuleEnabled(): bool {
    $path = defined('SETTINGS_PATH') ? SETTINGS_PATH : dirname(__DIR__) . '/content/settings.json';
    $settings = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    return !empty($settings['modules']['newsletter']);
}

function nibblyNewsletterSiteSettings(): array {
    $path = defined('SETTINGS_PATH') ? SETTINGS_PATH : dirname(__DIR__) . '/content/settings.json';
    $settings = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    return is_array($settings) ? $settings : [];
}

// ---------------------------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------------------------

function nibblyNewsletterSettingsDefaults(): array {
    return [
        'senderName' => '', 'senderEmail' => '', 'replyTo' => '',
        // site = the website's own mail setup, gmail = Gmail SMTP with an app password, smtp = own server
        'delivery' => 'site',
        'gmailUser' => '', 'gmailPassword' => '',
        'smtpHost' => '', 'smtpPort' => 587, 'smtpUsername' => '', 'smtpPassword' => '', 'smtpEncryption' => 'tls',
        'batchSize' => 20, 'dailyLimit' => 500,
        'doubleOptIn' => true, 'reminders' => 2, 'reminderHours' => 48,
        'trackOpens' => false, 'trackClicks' => false,
        'webVersion' => true, 'storeIp' => false, 'mailLog' => true,
        'accentColor' => '#3858e9', 'backgroundColor' => '#f3f4f6', 'logo' => '',
        'footerText' => '', 'consentText' => '',
        'fields' => [],
        'cronToken' => '',
    ];
}

function nibblyNewsletterSettings(): array {
    $stored = nibblyNewsletterRead('settings.json');
    $settings = array_replace(nibblyNewsletterSettingsDefaults(), array_intersect_key($stored, nibblyNewsletterSettingsDefaults()));
    if (!is_array($settings['fields'])) $settings['fields'] = [];
    return $settings;
}

/** Validates and stores settings; empty passwords keep the stored ones. */
function nibblyNewsletterSaveSettings(array $input): array {
    $current = nibblyNewsletterSettings();
    $next = $current;
    $text = static fn($value, int $max = 200): string => mb_substr(trim(str_replace(["\r", "\0"], '', (string)$value)), 0, $max);
    foreach (['senderName', 'gmailUser', 'smtpHost', 'smtpUsername', 'logo'] as $key) {
        if (array_key_exists($key, $input)) $next[$key] = str_replace("\n", ' ', $text($input[$key]));
    }
    foreach (['senderEmail', 'replyTo'] as $key) {
        if (!array_key_exists($key, $input)) continue;
        $value = $text($input[$key]);
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('newsletter.error_email');
        $next[$key] = $value;
    }
    if ($next['gmailUser'] !== '' && !filter_var($next['gmailUser'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('newsletter.error_email');
    if ($next['logo'] !== '' && (!str_starts_with($next['logo'], '/assets/images/') || str_contains($next['logo'], '..'))) $next['logo'] = '';
    foreach (['gmailPassword', 'smtpPassword'] as $key) {
        $value = (string)($input[$key] ?? '');
        // Google shows app passwords in groups of four separated by spaces.
        if ($value !== '') $next[$key] = $key === 'gmailPassword' ? str_replace(' ', '', $value) : $value;
    }
    if (array_key_exists('delivery', $input) && in_array($input['delivery'], ['site', 'gmail', 'smtp'], true)) $next['delivery'] = $input['delivery'];
    if (array_key_exists('smtpEncryption', $input) && in_array($input['smtpEncryption'], ['tls', 'ssl', 'none'], true)) $next['smtpEncryption'] = $input['smtpEncryption'];
    $ints = ['smtpPort' => [1, 65535], 'batchSize' => [1, 200], 'dailyLimit' => [0, 1000000], 'reminders' => [0, 5], 'reminderHours' => [1, 720]];
    foreach ($ints as $key => [$min, $max]) {
        if (array_key_exists($key, $input)) $next[$key] = max($min, min($max, (int)$input[$key]));
    }
    foreach (['doubleOptIn', 'trackOpens', 'trackClicks', 'webVersion', 'storeIp', 'mailLog'] as $key) {
        if (array_key_exists($key, $input)) $next[$key] = !empty($input[$key]);
    }
    foreach (['accentColor', 'backgroundColor'] as $key) {
        if (array_key_exists($key, $input) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$input[$key])) $next[$key] = strtolower($input[$key]);
    }
    foreach (['footerText' => 2000, 'consentText' => 1000] as $key => $max) {
        if (array_key_exists($key, $input)) $next[$key] = $text($input[$key], $max);
    }
    if (array_key_exists('fields', $input) && is_array($input['fields'])) {
        $fields = [];
        foreach (array_slice($input['fields'], 0, 20) as $field) {
            $label = str_replace("\n", ' ', $text($field['label'] ?? '', 60));
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($field['key'] ?? '')));
            if ($key === '') $key = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', $label)));
            if ($label === '' || $key === '' || isset($fields[$key]) || in_array($key, nibblyNewsletterReservedTags(), true)) continue;
            $fields[$key] = ['key' => $key, 'label' => $label];
        }
        $next['fields'] = array_values($fields);
    }
    if ($next['cronToken'] === '') $next['cronToken'] = bin2hex(random_bytes(24));
    if (!nibblyNewsletterUpdate('settings.json', function (array &$data) use ($next): void { $data = $next; })) {
        throw new RuntimeException('newsletter.error_storage');
    }
    return $next;
}

/** Settings for the dashboard: secrets replaced by flags. */
function nibblyNewsletterPublicSettings(array $settings): array {
    $settings['hasGmailPassword'] = $settings['gmailPassword'] !== '';
    $settings['hasSmtpPassword'] = $settings['smtpPassword'] !== '';
    unset($settings['gmailPassword'], $settings['smtpPassword']);
    return $settings;
}

function nibblyNewsletterCronToken(): string {
    $settings = nibblyNewsletterSettings();
    if ($settings['cronToken'] !== '') return $settings['cronToken'];
    return nibblyNewsletterSaveSettings([])['cronToken'];
}

function nibblyNewsletterSecret(): string {
    $data = nibblyNewsletterRead('secret.json');
    if (!empty($data['key'])) return (string)$data['key'];
    $key = bin2hex(random_bytes(32));
    nibblyNewsletterUpdate('secret.json', function (array &$stored) use (&$key): void {
        if (!empty($stored['key'])) { $key = (string)$stored['key']; return; }
        $stored = ['key' => $key];
    });
    return $key;
}

function nibblyNewsletterSign(string ...$parts): string {
    return substr(hash_hmac('sha256', implode('|', $parts), nibblyNewsletterSecret()), 0, 32);
}

// ---------------------------------------------------------------------------------------------
// URLs
// ---------------------------------------------------------------------------------------------

/**
 * Address of the site root for links in mails: the configured site address, never the Host
 * header of a public request. Purely local requests may use their own host (development).
 */
function nibblyNewsletterBaseUrl(): string {
    $site = nibblyNewsletterSiteSettings();
    $configured = trim((string)($site['seo']['siteUrl'] ?? ''));
    if ($configured === '' && defined('NIBBLY_SITE_URL')) $configured = trim((string)NIBBLY_SITE_URL);
    if ($configured !== '' && nibblySiteUrlOrigin($configured) !== '') return rtrim($configured, '/');
    if (PHP_SAPI === 'cli') return '';
    $origin = nibblyTrustedSiteOrigin();
    if ($origin === '') return '';
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = preg_replace('#/(admin|api)/[^/]*$#', '', $script);
    return $origin . ($base !== $script && preg_match('#^(/[A-Za-z0-9._~-]+)*$#', (string)$base) ? $base : '');
}

function nibblyNewsletterUrl(string $action, array $params = []): string {
    return nibblyNewsletterBaseUrl() . '/api/newsletter.php?' . http_build_query(['a' => $action] + $params);
}

function nibblyNewsletterAbsoluteUrl(string $url): string {
    $url = trim($url);
    if ($url === '' || preg_match('#^(https?:|mailto:|tel:|\#|\{)#i', $url)) return $url;
    if (str_starts_with($url, '//')) return 'https:' . $url;
    return nibblyNewsletterBaseUrl() . '/' . ltrim($url, '/');
}

// ---------------------------------------------------------------------------------------------
// Lists and subscribers
// ---------------------------------------------------------------------------------------------

function nibblyNewsletterLists(): array {
    $lists = nibblyNewsletterRead('lists.json')['lists'] ?? [];
    return array_values(array_filter(is_array($lists) ? $lists : [], 'is_array'));
}

function nibblyNewsletterSubscribers(): array {
    $subscribers = nibblyNewsletterRead('subscribers.json')['subscribers'] ?? [];
    return is_array($subscribers) ? $subscribers : [];
}

function nibblyNewsletterNormalizeEmail(string $email): string {
    $email = strtolower(trim($email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function nibblyNewsletterFindByEmail(array $subscribers, string $email): ?string {
    foreach ($subscribers as $id => $subscriber) {
        if (($subscriber['email'] ?? '') === $email) return (string)$id;
    }
    return null;
}

/** Cleans the editable part of a subscriber record. */
function nibblyNewsletterCleanSubscriber(array $input, array $settings): array {
    $line = static fn($value, int $max = 120): string => mb_substr(trim(preg_replace('/[\x00-\x1f]+/', ' ', (string)$value)), 0, $max);
    $clean = ['firstName' => $line($input['firstName'] ?? ''), 'lastName' => $line($input['lastName'] ?? '')];
    $listIds = array_column(nibblyNewsletterLists(), 'id');
    $clean['lists'] = array_values(array_unique(array_intersect(array_map('strval', (array)($input['lists'] ?? [])), $listIds)));
    $tags = [];
    foreach ((array)($input['tags'] ?? []) as $tag) {
        $tag = $line($tag, 40);
        if ($tag !== '') $tags[mb_strtolower($tag)] = $tag;
    }
    $clean['tags'] = array_values(array_slice($tags, 0, 30));
    $clean['fields'] = [];
    foreach ($settings['fields'] as $field) {
        $value = $line(((array)($input['fields'] ?? []))[$field['key']] ?? '', 300);
        if ($value !== '') $clean['fields'][$field['key']] = $value;
    }
    return $clean;
}

function nibblyNewsletterNewSubscriber(string $email, string $status, string $source): array {
    return [
        'id' => 'sub_' . bin2hex(random_bytes(8)), 'email' => $email, 'firstName' => '', 'lastName' => '',
        'status' => $status, 'lists' => [], 'tags' => [], 'fields' => [],
        'token' => bin2hex(random_bytes(16)), 'lang' => '', 'source' => $source,
        'createdAt' => date('c'), 'confirmedAt' => $status === 'subscribed' ? date('c') : null,
        'unsubscribedAt' => null, 'confirmSent' => 0, 'confirmSentAt' => null,
    ];
}

/**
 * Public sign-up. Returns ['result' => 'pending'|'subscribed'|'exists', 'subscriber' => array].
 * An existing confirmed address only gains the new lists; nothing is revealed to the visitor.
 */
function nibblyNewsletterSubscribe(string $email, array $data, string $lang, string $source = 'form'): array {
    $settings = nibblyNewsletterSettings();
    $email = nibblyNewsletterNormalizeEmail($email);
    if ($email === '') throw new InvalidArgumentException('email');
    $clean = nibblyNewsletterCleanSubscriber($data, $settings);
    $result = ['result' => 'pending', 'subscriber' => null];
    $ok = nibblyNewsletterUpdate('subscribers.json', function (array &$store) use ($email, $clean, $lang, $source, $settings, &$result): void {
        $store['subscribers'] = is_array($store['subscribers'] ?? null) ? $store['subscribers'] : [];
        $id = nibblyNewsletterFindByEmail($store['subscribers'], $email);
        $subscriber = $id !== null ? $store['subscribers'][$id] : nibblyNewsletterNewSubscriber($email, 'pending', $source);
        $wasActive = $id !== null && ($subscriber['status'] ?? '') === 'subscribed';
        foreach (['firstName', 'lastName'] as $key) if ($clean[$key] !== '') $subscriber[$key] = $clean[$key];
        $subscriber['lists'] = array_values(array_unique(array_merge($subscriber['lists'] ?? [], $clean['lists'])));
        $subscriber['fields'] = $clean['fields'] + ($subscriber['fields'] ?? []);
        if ($lang !== '') $subscriber['lang'] = $lang;
        $consent = ['at' => date('c'), 'text' => $settings['consentText']];
        if ($settings['storeIp']) $consent['ip'] = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $subscriber['consent'] = $consent;
        if ($wasActive) {
            $result['result'] = 'exists';
        } elseif ($settings['doubleOptIn']) {
            $subscriber['status'] = 'pending';
            $subscriber['confirmSent'] = 0;
        } else {
            $subscriber['status'] = 'subscribed';
            $subscriber['confirmedAt'] = date('c');
            $subscriber['unsubscribedAt'] = null;
            $result['result'] = 'subscribed';
        }
        $store['subscribers'][$subscriber['id']] = $subscriber;
        $result['subscriber'] = $subscriber;
    }, ['subscribers' => []]);
    if (!$ok) throw new RuntimeException('storage');
    return $result;
}

/** Confirms a pending subscriber; true also when already confirmed. */
function nibblyNewsletterConfirm(string $id, string $token): bool {
    $done = false;
    nibblyNewsletterUpdate('subscribers.json', function (array &$store) use ($id, $token, &$done) {
        $subscriber = $store['subscribers'][$id] ?? null;
        if (!is_array($subscriber) || !hash_equals((string)($subscriber['token'] ?? ''), $token)) return false;
        $done = true;
        if ($subscriber['status'] !== 'pending') return false;
        $subscriber['status'] = 'subscribed';
        $subscriber['confirmedAt'] = date('c');
        $subscriber['unsubscribedAt'] = null;
        $store['subscribers'][$id] = $subscriber;
        return true;
    }, ['subscribers' => []]);
    return $done;
}

function nibblyNewsletterUnsubscribe(string $id, string $token, string $campaignId = ''): bool {
    $done = false;
    $changed = false;
    nibblyNewsletterUpdate('subscribers.json', function (array &$store) use ($id, $token, &$done, &$changed) {
        $subscriber = $store['subscribers'][$id] ?? null;
        if (!is_array($subscriber) || !hash_equals((string)($subscriber['token'] ?? ''), $token)) return false;
        $done = true;
        if ($subscriber['status'] === 'unsubscribed') return false;
        $subscriber['status'] = 'unsubscribed';
        $subscriber['unsubscribedAt'] = date('c');
        $store['subscribers'][$id] = $subscriber;
        $changed = true;
        return true;
    }, ['subscribers' => []]);
    if ($changed && $campaignId !== '') nibblyNewsletterRecordEvent($campaignId, $id, 'u');
    return $done;
}

function nibblyNewsletterSetStatus(string $id, string $status): void {
    nibblyNewsletterUpdate('subscribers.json', function (array &$store) use ($id, $status) {
        if (!isset($store['subscribers'][$id])) return false;
        $store['subscribers'][$id]['status'] = $status;
        return true;
    }, ['subscribers' => []]);
}

// ---------------------------------------------------------------------------------------------
// Campaigns
// ---------------------------------------------------------------------------------------------

function nibblyNewsletterValidId(string $id): bool {
    return (bool)preg_match('/^nl_[a-f0-9]{12}$/', $id);
}

function nibblyNewsletterCampaign(string $id): ?array {
    if (!nibblyNewsletterValidId($id)) return null;
    $campaign = nibblyNewsletterRead('campaigns/' . $id . '.json');
    return $campaign ? $campaign + ['stats' => []] : null;
}

function nibblyNewsletterCampaigns(): array {
    $campaigns = [];
    foreach (glob(nibblyNewsletterPath('campaigns/nl_*.json')) ?: [] as $file) {
        if (str_ends_with($file, '.rcpt.json')) continue;
        $campaign = json_decode((string)file_get_contents($file), true);
        if (is_array($campaign) && !empty($campaign['id'])) $campaigns[] = $campaign;
    }
    usort($campaigns, static fn($a, $b) => strcmp((string)($b['updatedAt'] ?? ''), (string)($a['updatedAt'] ?? '')));
    return $campaigns;
}

function nibblyNewsletterBlockTypes(): array {
    return ['heading', 'text', 'image', 'button', 'divider', 'spacer', 'post'];
}

function nibblyNewsletterCleanBlocks($blocks): array {
    $clean = [];
    $line = static fn($value, int $max = 300): string => mb_substr(trim(preg_replace('/[\x00-\x1f]+/', ' ', (string)$value)), 0, $max);
    $url = static function ($value): string {
        $value = trim((string)$value);
        if ($value === '' || str_starts_with($value, '{')) return $value;
        $safe = nibblySanitizeHref($value);
        return $safe === '#' ? '' : $safe;
    };
    foreach (array_slice(is_array($blocks) ? $blocks : [], 0, 60) as $block) {
        if (!is_array($block) || !in_array($block['type'] ?? '', nibblyNewsletterBlockTypes(), true)) continue;
        $type = $block['type'];
        $item = ['type' => $type];
        if ($type === 'heading') {
            $item += ['text' => $line($block['text'] ?? ''), 'level' => in_array((int)($block['level'] ?? 2), [1, 2, 3], true) ? (int)$block['level'] : 2,
                'align' => ($block['align'] ?? '') === 'center' ? 'center' : 'left'];
        } elseif ($type === 'text') {
            $item['html'] = nibblySanitizeRichHtml(mb_substr((string)($block['html'] ?? ''), 0, 60000));
        } elseif ($type === 'image') {
            $item += ['src' => $url($block['src'] ?? ''), 'alt' => $line($block['alt'] ?? ''), 'link' => $url($block['link'] ?? '')];
        } elseif ($type === 'button') {
            $item += ['label' => $line($block['label'] ?? '', 80), 'url' => $url($block['url'] ?? ''),
                'align' => ($block['align'] ?? '') === 'left' ? 'left' : 'center'];
        } elseif ($type === 'spacer') {
            $item['height'] = max(8, min(80, (int)($block['height'] ?? 24)));
        } elseif ($type === 'post') {
            $item += ['title' => $line($block['title'] ?? ''), 'excerpt' => $line($block['excerpt'] ?? '', 600), 'image' => $url($block['image'] ?? ''),
                'url' => $url($block['url'] ?? ''), 'label' => $line($block['label'] ?? '', 80)];
        }
        $clean[] = $item;
    }
    return $clean;
}

/** Creates or updates a draft; a campaign that is being sent keeps its content. */
function nibblyNewsletterSaveCampaign(array $input, string $id = ''): array {
    $existing = $id !== '' ? nibblyNewsletterCampaign($id) : null;
    if ($id !== '' && $existing === null) throw new InvalidArgumentException('newsletter.error_not_found');
    if ($existing && !in_array($existing['status'], ['draft', 'scheduled'], true)) throw new InvalidArgumentException('newsletter.error_locked');
    $line = static fn($value, int $max = 200): string => mb_substr(trim(preg_replace('/[\x00-\x1f]+/', ' ', (string)$value)), 0, $max);
    $listIds = array_column(nibblyNewsletterLists(), 'id');
    $campaign = $existing ?? ['id' => 'nl_' . bin2hex(random_bytes(6)), 'status' => 'draft', 'createdAt' => date('c'), 'sendAt' => null, 'stats' => []];
    $campaign['subject'] = $line($input['subject'] ?? '');
    $campaign['preheader'] = $line($input['preheader'] ?? '', 250);
    $campaign['lists'] = array_values(array_intersect(array_map('strval', (array)($input['lists'] ?? [])), $listIds));
    $campaign['blocks'] = nibblyNewsletterCleanBlocks($input['blocks'] ?? []);
    $campaign['updatedAt'] = date('c');
    nibblyNewsletterWriteCampaign($campaign);
    return $campaign;
}

function nibblyNewsletterWriteCampaign(array $campaign): void {
    if (!nibblyJsonAtomicWrite(nibblyNewsletterPath('campaigns/' . $campaign['id'] . '.json'), $campaign)) {
        throw new RuntimeException('newsletter.error_storage');
    }
}

/** Locked change of a stored campaign (status, counters). */
function nibblyNewsletterUpdateCampaign(string $id, callable $update): ?array {
    if (!nibblyNewsletterValidId($id)) return null;
    $result = null;
    nibblyNewsletterUpdate('campaigns/' . $id . '.json', function (array &$campaign) use ($update, &$result) {
        if (empty($campaign['id'])) return false;
        if ($update($campaign) === false) { $result = $campaign; return false; }
        $result = $campaign;
        return true;
    });
    return $result;
}

function nibblyNewsletterDeleteCampaign(string $id): void {
    if (!nibblyNewsletterValidId($id)) return;
    foreach (['.json', '.json.lock', '.rcpt.json', '.rcpt.json.lock'] as $suffix) @unlink(nibblyNewsletterPath('campaigns/' . $id . $suffix));
}

/** Subscribers a campaign goes to: confirmed, and in one of its lists (no list = everyone). */
function nibblyNewsletterRecipients(array $campaign, ?array $subscribers = null): array {
    $subscribers = $subscribers ?? nibblyNewsletterSubscribers();
    $lists = $campaign['lists'] ?? [];
    $ids = [];
    foreach ($subscribers as $id => $subscriber) {
        if (($subscriber['status'] ?? '') !== 'subscribed') continue;
        if ($lists && !array_intersect($lists, $subscriber['lists'] ?? [])) continue;
        $ids[] = (string)$id;
    }
    return $ids;
}

/** Counts an event of one recipient: o = open, c = click, u = unsubscribe. */
function nibblyNewsletterRecordEvent(string $campaignId, string $subscriberId, string $event, string $link = ''): void {
    if (!nibblyNewsletterValidId($campaignId)) return;
    $counters = [];
    nibblyNewsletterUpdate('campaigns/' . $campaignId . '.rcpt.json', function (array &$data) use ($subscriberId, $event, $link, &$counters) {
        if (!isset($data['done'][$subscriberId])) return false;
        $entry = &$data['done'][$subscriberId];
        if ($event === 'c') {
            if (empty($entry['c'])) $counters[] = 'clicks';
            $entry['c'] = (int)($entry['c'] ?? 0) + 1;
            if ($link !== '') $data['links'][$link] = (int)($data['links'][$link] ?? 0) + 1;
            // A click proves the mail was opened, also when images were blocked.
            if (empty($entry['o'])) { $entry['o'] = time(); $counters[] = 'opens'; }
        } elseif (empty($entry[$event])) {
            $entry[$event] = time();
            $counters[] = $event === 'o' ? 'opens' : 'unsubs';
        } else {
            return false;
        }
        return true;
    });
    if (!$counters) return;
    nibblyNewsletterUpdateCampaign($campaignId, function (array &$campaign) use ($counters): void {
        foreach ($counters as $key) $campaign['stats'][$key] = (int)($campaign['stats'][$key] ?? 0) + 1;
    });
}

// ---------------------------------------------------------------------------------------------
// Visitor-facing strings (page language; English fallback)
// ---------------------------------------------------------------------------------------------

function nibblyNewsletterString(string $key, string $lang = '', array $params = []): string {
    static $strings = [
        'email' => ['en' => 'Email address', 'de' => 'E-Mail-Adresse'],
        'first_name' => ['en' => 'First name', 'de' => 'Vorname'],
        'last_name' => ['en' => 'Last name', 'de' => 'Nachname'],
        'subscribe' => ['en' => 'Subscribe', 'de' => 'Anmelden'],
        'consent_default' => ['en' => 'I would like to receive the newsletter by email. I can unsubscribe at any time.',
            'de' => 'Ich möchte den Newsletter per E-Mail erhalten. Die Abmeldung ist jederzeit möglich.'],
        'consent_required' => ['en' => 'Please confirm that you want to receive the newsletter.', 'de' => 'Bitte bestätige, dass du den Newsletter erhalten möchtest.'],
        'email_invalid' => ['en' => 'Please enter a valid email address.', 'de' => 'Bitte gib eine gültige E-Mail-Adresse ein.'],
        'too_many' => ['en' => 'Too many attempts. Please try again later.', 'de' => 'Zu viele Versuche. Bitte versuch es später noch einmal.'],
        'try_again' => ['en' => 'That did not work. Please try again.', 'de' => 'Das hat nicht geklappt. Bitte versuch es noch einmal.'],
        'pending_title' => ['en' => 'Almost done', 'de' => 'Fast geschafft'],
        'pending_text' => ['en' => 'We have sent you an email. Please click the link in it to confirm your subscription.',
            'de' => 'Wir haben dir eine E-Mail geschickt. Bitte klick auf den Link darin, um deine Anmeldung zu bestätigen.'],
        'subscribed_title' => ['en' => 'Subscription confirmed', 'de' => 'Anmeldung bestätigt'],
        'subscribed_text' => ['en' => 'Thank you. You will receive our newsletter from now on.', 'de' => 'Vielen Dank. Du erhältst ab jetzt unseren Newsletter.'],
        'invalid_title' => ['en' => 'Link not valid', 'de' => 'Link ungültig'],
        'invalid_text' => ['en' => 'This link is not valid or no longer current.', 'de' => 'Dieser Link ist ungültig oder nicht mehr aktuell.'],
        'unsubscribe_title' => ['en' => 'Unsubscribe from the newsletter', 'de' => 'Newsletter abmelden'],
        'unsubscribe_text' => ['en' => 'Do you want to stop receiving the newsletter at {email}?', 'de' => 'Möchtest du den Newsletter an {email} nicht mehr erhalten?'],
        'unsubscribe_button' => ['en' => 'Unsubscribe', 'de' => 'Abmelden'],
        'unsubscribed_title' => ['en' => 'You are unsubscribed', 'de' => 'Du bist abgemeldet'],
        'unsubscribed_text' => ['en' => 'You will not receive any further newsletters from us.', 'de' => 'Du erhältst keine weiteren Newsletter von uns.'],
        'back_home' => ['en' => 'Back to the website', 'de' => 'Zur Website'],
        'confirm_subject' => ['en' => 'Please confirm your subscription', 'de' => 'Bitte bestätige deine Anmeldung'],
        'confirm_heading' => ['en' => 'Just one more click', 'de' => 'Nur noch ein Klick'],
        'confirm_text' => ['en' => 'Please confirm that you want to receive the newsletter of {site} at this address. If you did not sign up, simply ignore this email.',
            'de' => 'Bitte bestätige, dass du den Newsletter von {site} an diese Adresse erhalten möchtest. Falls du dich nicht angemeldet hast, ignoriere diese E-Mail einfach.'],
        'confirm_button' => ['en' => 'Confirm subscription', 'de' => 'Anmeldung bestätigen'],
        'footer_unsubscribe' => ['en' => 'Unsubscribe', 'de' => 'Abmelden'],
        'footer_web' => ['en' => 'View in browser', 'de' => 'Im Browser ansehen'],
        'footer_reason' => ['en' => 'You receive this email because you subscribed to the newsletter of {site}.',
            'de' => 'Du erhältst diese E-Mail, weil du den Newsletter von {site} abonniert hast.'],
        'read_more' => ['en' => 'Read more', 'de' => 'Weiterlesen'],
        'not_available' => ['en' => 'This newsletter is not available.', 'de' => 'Dieser Newsletter ist nicht verfügbar.'],
    ];
    $lang = strtolower($lang !== '' ? $lang : (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'));
    $text = $strings[$key][$lang] ?? $strings[$key]['en'] ?? $key;
    foreach ($params as $name => $value) $text = str_replace('{' . $name . '}', (string)$value, $text);
    return $text;
}

function nibblyNewsletterSiteName(): string {
    $site = nibblyNewsletterSiteSettings();
    $name = trim((string)($site['branding']['name'] ?? ''));
    return $name !== '' ? $name : (defined('SITE_NAME') ? SITE_NAME : 'Website');
}

// ---------------------------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------------------------

function nibblyNewsletterReservedTags(): array {
    return ['firstname', 'lastname', 'name', 'email', 'sitename', 'unsubscribe_url', 'webversion_url', 'year'];
}

/** Values of the merge tags for one subscriber (null = preview without a person). */
function nibblyNewsletterTagValues(?array $subscriber, array $context): array {
    $values = [
        'firstname' => (string)($subscriber['firstName'] ?? ''),
        'lastname' => (string)($subscriber['lastName'] ?? ''),
        'name' => trim(($subscriber['firstName'] ?? '') . ' ' . ($subscriber['lastName'] ?? '')),
        'email' => (string)($subscriber['email'] ?? ''),
        'sitename' => nibblyNewsletterSiteName(),
        'unsubscribe_url' => $context['unsubscribeUrl'] ?? '#',
        'webversion_url' => $context['webUrl'] ?? '#',
        'year' => date('Y'),
    ];
    foreach ((array)($subscriber['fields'] ?? []) as $key => $value) {
        if (!isset($values[$key])) $values[(string)$key] = (string)$value;
    }
    return $values;
}

/** Replaces {tag} and {tag|fallback}; unknown tags are removed, URLs stay unescaped in attributes. */
function nibblyNewsletterApplyTags(string $text, array $values, bool $html = true): string {
    return preg_replace_callback('/\{([a-z0-9_]+)(?:\|([^{}]*))?\}/i', static function (array $match) use ($values, $html): string {
        $key = strtolower($match[1]);
        $value = trim((string)($values[$key] ?? ''));
        if ($value === '') $value = $html ? html_entity_decode($match[2] ?? '', ENT_QUOTES, 'UTF-8') : ($match[2] ?? '');
        return $html ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
    }, $text);
}

/** Adds inline styles to the tags of sanitised rich text and makes addresses absolute. */
function nibblyNewsletterStyleRichText(string $html, string $accent): string {
    $styles = [
        'p' => 'margin:0 0 16px;', 'h2' => 'margin:24px 0 12px;font-size:22px;line-height:1.3;color:#111827;',
        'h3' => 'margin:20px 0 10px;font-size:18px;line-height:1.3;color:#111827;', 'h4' => 'margin:16px 0 8px;font-size:16px;color:#111827;',
        'ul' => 'margin:0 0 16px;padding-left:22px;', 'ol' => 'margin:0 0 16px;padding-left:22px;', 'li' => 'margin:0 0 6px;',
        'a' => 'color:' . $accent . ';text-decoration:underline;',
        'blockquote' => 'margin:0 0 16px;padding:4px 0 4px 16px;border-left:3px solid ' . $accent . ';color:#4b5563;',
        'img' => 'max-width:100%;height:auto;border:0;', 'hr' => 'border:0;border-top:1px solid #e5e7eb;margin:24px 0;',
    ];
    $html = preg_replace_callback('/<(p|h2|h3|h4|ul|ol|li|a|blockquote|img|hr)\b([^>]*)>/i', static function (array $match) use ($styles): string {
        $tag = strtolower($match[1]);
        $attributes = $match[2];
        $own = '';
        if (preg_match('/\sstyle="([^"]*)"/i', $attributes, $style)) {
            $own = rtrim($style[1], '; ') . ';';
            $attributes = str_replace($style[0], '', $attributes);
        }
        return '<' . $tag . $attributes . ' style="' . $styles[$tag] . $own . '">';
    }, $html);
    return preg_replace_callback('/\s(href|src)="([^"]*)"/i', static function (array $match): string {
        return ' ' . strtolower($match[1]) . '="' . htmlspecialchars(nibblyNewsletterAbsoluteUrl(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8')), ENT_QUOTES, 'UTF-8') . '"';
    }, $html);
}

function nibblyNewsletterButtonHtml(string $label, string $url, string $accent, string $align = 'center'): string {
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="' . $align . '" style="margin:8px ' . ($align === 'center' ? 'auto' : '0') . ' 24px;">'
        . '<tr><td bgcolor="' . $accent . '" style="border-radius:8px;background:' . $accent . ';">'
        . '<a href="' . $e($url) . '" style="display:inline-block;padding:12px 26px;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:8px;">'
        . $e($label) . '</a></td></tr></table>';
}

function nibblyNewsletterBlocksHtml(array $blocks, string $accent, string $lang): string {
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $html = '';
    foreach ($blocks as $block) {
        switch ($block['type'] ?? '') {
            case 'heading':
                $size = [1 => 30, 2 => 24, 3 => 19][$block['level'] ?? 2];
                $html .= '<h' . $block['level'] . ' style="margin:0 0 16px;font-size:' . $size . 'px;line-height:1.25;color:#111827;text-align:' . $block['align'] . ';">' . $e($block['text']) . '</h' . $block['level'] . '>';
                break;
            case 'text':
                $html .= '<div style="font-size:16px;line-height:1.6;color:#374151;">' . nibblyNewsletterStyleRichText($block['html'], $accent) . '</div>';
                break;
            case 'image':
                if ($block['src'] === '') break;
                $image = '<img src="' . $e(nibblyNewsletterAbsoluteUrl($block['src'])) . '" alt="' . $e($block['alt']) . '" width="536" style="display:block;width:100%;max-width:536px;height:auto;border:0;border-radius:8px;">';
                if ($block['link'] !== '') $image = '<a href="' . $e(nibblyNewsletterAbsoluteUrl($block['link'])) . '">' . $image . '</a>';
                $html .= '<div style="margin:0 0 20px;">' . $image . '</div>';
                break;
            case 'button':
                if ($block['label'] !== '' && $block['url'] !== '') $html .= nibblyNewsletterButtonHtml($block['label'], nibblyNewsletterAbsoluteUrl($block['url']), $accent, $block['align']);
                break;
            case 'divider':
                $html .= '<div style="border-top:1px solid #e5e7eb;margin:8px 0 24px;font-size:0;line-height:0;">&nbsp;</div>';
                break;
            case 'spacer':
                $html .= '<div style="height:' . (int)$block['height'] . 'px;font-size:0;line-height:0;">&nbsp;</div>';
                break;
            case 'post':
                $url = nibblyNewsletterAbsoluteUrl($block['url']);
                $card = '';
                if ($block['image'] !== '') {
                    $card .= '<a href="' . $e($url) . '"><img src="' . $e(nibblyNewsletterAbsoluteUrl($block['image'])) . '" alt="" width="536" style="display:block;width:100%;max-width:536px;height:auto;border:0;border-radius:8px 8px 0 0;"></a>';
                }
                $card .= '<div style="padding:18px 20px 4px;">'
                    . '<h3 style="margin:0 0 8px;font-size:19px;line-height:1.3;"><a href="' . $e($url) . '" style="color:#111827;text-decoration:none;">' . $e($block['title']) . '</a></h3>'
                    . ($block['excerpt'] !== '' ? '<p style="margin:0 0 14px;font-size:15px;line-height:1.55;color:#4b5563;">' . $e($block['excerpt']) . '</p>' : '')
                    . ($url !== '' ? '<p style="margin:0 0 14px;font-size:15px;"><a href="' . $e($url) . '" style="color:' . $accent . ';font-weight:bold;text-decoration:none;">'
                        . $e($block['label'] !== '' ? $block['label'] : nibblyNewsletterString('read_more', $lang)) . ' &rarr;</a></p>' : '')
                    . '</div>';
                $html .= '<div style="margin:0 0 24px;border:1px solid #e5e7eb;border-radius:8px;">' . $card . '</div>';
                break;
        }
    }
    return $html;
}

function nibblyNewsletterBlocksText(array $blocks, string $lang): string {
    $text = '';
    $plain = static function (string $html): string {
        $html = preg_replace('#<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html);
        $html = preg_replace('#<li\b[^>]*>#i', '- ', $html);
        $html = preg_replace('#</(p|h[1-6]|li|div|blockquote)>|<br\s*/?>#i', "\n", $html);
        return trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    };
    foreach ($blocks as $block) {
        switch ($block['type'] ?? '') {
            case 'heading': $text .= mb_strtoupper($block['text']) . "\n\n"; break;
            case 'text': $text .= $plain(preg_replace_callback('/href="([^"]*)"/i', static fn($m) => 'href="' . nibblyNewsletterAbsoluteUrl(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')) . '"', $block['html'])) . "\n\n"; break;
            case 'button': if ($block['url'] !== '') $text .= $block['label'] . ': ' . nibblyNewsletterAbsoluteUrl($block['url']) . "\n\n"; break;
            case 'divider': $text .= "----------------------------------------\n\n"; break;
            case 'post': $text .= $block['title'] . "\n" . ($block['excerpt'] !== '' ? $block['excerpt'] . "\n" : '') . nibblyNewsletterAbsoluteUrl($block['url']) . "\n\n"; break;
        }
    }
    return $text;
}

/**
 * The finished mail of a campaign for one subscriber.
 * $options: preview (no tracking, placeholder links), web (browser version).
 * Returns ['subject', 'html', 'text', 'headers'].
 */
function nibblyNewsletterRender(array $campaign, ?array $subscriber, array $options = []): array {
    $settings = $options['settings'] ?? nibblyNewsletterSettings();
    $lang = (string)(($subscriber['lang'] ?? '') ?: (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'));
    $preview = !empty($options['preview']);
    $web = !empty($options['web']);
    $system = !empty($options['system']);
    $accent = $settings['accentColor'];
    $siteName = nibblyNewsletterSiteName();
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $context = ['unsubscribeUrl' => '#', 'webUrl' => '#'];
    $real = $subscriber && !empty($subscriber['id']) && !empty($subscriber['token']) && !$preview;
    if ($real) {
        $context['unsubscribeUrl'] = nibblyNewsletterUrl('unsubscribe', ['s' => $subscriber['id'], 't' => $subscriber['token']] + (!$system && !empty($campaign['id']) ? ['c' => $campaign['id']] : []));
    }
    $hasWeb = !$system && $settings['webVersion'] && !empty($campaign['id']);
    if ($hasWeb && !$preview) $context['webUrl'] = nibblyNewsletterUrl('view', ['c' => $campaign['id'], 'h' => nibblyNewsletterSign('view', $campaign['id'])]);
    $values = nibblyNewsletterTagValues($subscriber, $context);

    $body = nibblyNewsletterBlocksHtml($campaign['blocks'] ?? [], $accent, $lang);
    $logo = '';
    if ($settings['logo'] !== '') {
        $logo = '<tr><td align="center" style="padding:28px 32px 0;"><img src="' . $e(nibblyNewsletterAbsoluteUrl($settings['logo'])) . '" alt="' . $e($siteName) . '" style="display:block;max-width:200px;max-height:80px;height:auto;border:0;"></td></tr>';
    }
    $footer = [];
    if ($settings['footerText'] !== '') $footer[] = nl2br($e($settings['footerText']));
    if (!$system) {
        $footer[] = $e(nibblyNewsletterString('footer_reason', $lang, ['site' => $siteName]));
        $links = [];
        if (!$web) $links[] = '<a href="' . $e($context['unsubscribeUrl']) . '" style="color:#6b7280;text-decoration:underline;">' . $e(nibblyNewsletterString('footer_unsubscribe', $lang)) . '</a>';
        if ($hasWeb && !$web) $links[] = '<a href="' . $e($context['webUrl']) . '" style="color:#6b7280;text-decoration:underline;">' . $e(nibblyNewsletterString('footer_web', $lang)) . '</a>';
        if ($links) $footer[] = implode(' &nbsp;|&nbsp; ', $links);
    }
    $preheader = trim((string)($campaign['preheader'] ?? ''));
    $subject = nibblyNewsletterApplyTags((string)($campaign['subject'] ?? ''), $values, false);

    $html = '<!DOCTYPE html><html lang="' . $e($lang) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light"><title>' . $e($subject) . '</title>'
        . ($web ? '<meta name="robots" content="noindex">' : '')
        . '<style>@media (max-width:620px){.nl-card{width:100%!important;border-radius:0!important}.nl-pad{padding-left:20px!important;padding-right:20px!important}}</style></head>'
        . '<body style="margin:0;padding:0;background:' . $settings['backgroundColor'] . ';">'
        . ($preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . $e($preheader) . '</div>' : '')
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $settings['backgroundColor'] . '" style="background:' . $settings['backgroundColor'] . ';">'
        . '<tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" class="nl-card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:12px;">'
        . $logo
        . '<tr><td class="nl-pad" style="padding:32px 32px 12px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.6;color:#374151;">' . $body . '</td></tr>'
        . '</table>'
        . '<table role="presentation" class="nl-card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;">'
        . '<tr><td class="nl-pad" align="center" style="padding:20px 32px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.6;color:#6b7280;">' . implode('<br><br>', $footer) . '</td></tr>'
        . '</table></td></tr></table><!--nl:pixel--></body></html>';
    $html = nibblyNewsletterApplyTags($html, $values);

    $trackable = $real && !$system && !empty($campaign['id']);
    if ($trackable && !empty($campaign['trackClicks'])) {
        $skip = [$context['unsubscribeUrl'], $context['webUrl']];
        $html = preg_replace_callback('/<a\b([^>]*?)href="(https?:\/\/[^"]+)"/i', static function (array $match) use ($campaign, $subscriber, $skip): string {
            $target = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
            if (in_array($target, $skip, true)) return $match[0];
            $encoded = rtrim(strtr(base64_encode($target), '+/', '-_'), '=');
            $url = nibblyNewsletterUrl('click', ['c' => $campaign['id'], 's' => $subscriber['id'], 'u' => $encoded,
                'h' => nibblyNewsletterSign('click', $campaign['id'], $subscriber['id'], $target)]);
            return '<a' . $match[1] . 'href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
        }, $html);
    }
    $pixel = '';
    if ($trackable && !empty($campaign['trackOpens'])) {
        $pixel = '<img src="' . $e(nibblyNewsletterUrl('open', ['c' => $campaign['id'], 's' => $subscriber['id'], 'h' => nibblyNewsletterSign('open', $campaign['id'], $subscriber['id'])])) . '" width="1" height="1" alt="" style="display:block;border:0;">';
    }
    $html = str_replace('<!--nl:pixel-->', $pixel, $html);

    $text = nibblyNewsletterBlocksText($campaign['blocks'] ?? [], $lang);
    if ($settings['footerText'] !== '') $text .= "--\n" . $settings['footerText'] . "\n\n";
    if (!$system) {
        $text .= nibblyNewsletterString('footer_unsubscribe', $lang) . ': ' . $context['unsubscribeUrl'] . "\n";
        if ($hasWeb) $text .= nibblyNewsletterString('footer_web', $lang) . ': ' . $context['webUrl'] . "\n";
    }
    $text = nibblyNewsletterApplyTags($text, $values, false);

    $headers = [];
    if ($real && !$system) {
        $headers['List-Unsubscribe'] = '<' . $context['unsubscribeUrl'] . '>';
        $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        $headers['Precedence'] = 'bulk';
        $headers['X-Campaign'] = (string)$campaign['id'];
    }
    return ['subject' => $subject, 'html' => $html, 'text' => $text, 'headers' => $headers];
}

/** The confirmation mail of the double opt-in. */
function nibblyNewsletterConfirmMail(array $subscriber): array {
    $lang = (string)($subscriber['lang'] ?? '');
    $url = nibblyNewsletterUrl('confirm', ['s' => $subscriber['id'], 't' => $subscriber['token']]);
    $mail = [
        'subject' => nibblyNewsletterString('confirm_subject', $lang),
        'blocks' => [
            ['type' => 'heading', 'text' => nibblyNewsletterString('confirm_heading', $lang), 'level' => 2, 'align' => 'left'],
            ['type' => 'text', 'html' => '<p>' . htmlspecialchars(nibblyNewsletterString('confirm_text', $lang, ['site' => nibblyNewsletterSiteName()]), ENT_QUOTES, 'UTF-8') . '</p>'],
            ['type' => 'button', 'label' => nibblyNewsletterString('confirm_button', $lang), 'url' => $url, 'align' => 'left'],
        ],
    ];
    return nibblyNewsletterRender($mail, $subscriber, ['system' => true]);
}

// ---------------------------------------------------------------------------------------------
// Delivery
// ---------------------------------------------------------------------------------------------

/** Sender address and transport from the settings. Returns ['ok' => bool, 'error' => key, ...]. */
function nibblyNewsletterTransport(?array $settings = null): array {
    $settings = $settings ?? nibblyNewsletterSettings();
    $site = nibblyNewsletterSiteSettings()['email'] ?? [];
    $transport = ['ok' => false, 'error' => '', 'method' => 'smtp', 'fromName' => $settings['senderName'] !== '' ? $settings['senderName'] : nibblyNewsletterSiteName(),
        'fromEmail' => $settings['senderEmail'], 'replyTo' => $settings['replyTo']];
    if ($settings['delivery'] === 'gmail') {
        if ($settings['gmailUser'] === '' || $settings['gmailPassword'] === '') return ['error' => 'newsletter.error_gmail_setup'] + $transport;
        // Gmail only sends from the account itself (or its verified aliases).
        if ($transport['fromEmail'] === '') $transport['fromEmail'] = $settings['gmailUser'];
        $transport += ['host' => 'smtp.gmail.com', 'port' => 587, 'user' => $settings['gmailUser'], 'pass' => $settings['gmailPassword'], 'enc' => 'tls'];
    } elseif ($settings['delivery'] === 'smtp') {
        if ($settings['smtpHost'] === '') return ['error' => 'newsletter.error_smtp_setup'] + $transport;
        $transport += ['host' => $settings['smtpHost'], 'port' => (int)$settings['smtpPort'], 'user' => $settings['smtpUsername'], 'pass' => $settings['smtpPassword'], 'enc' => $settings['smtpEncryption']];
    } else {
        $method = $site['method'] ?? 'inactive';
        if ($method === 'inactive') return ['error' => 'newsletter.error_site_mail'] + $transport;
        if ($transport['fromEmail'] === '') $transport['fromEmail'] = (string)($site['fromEmail'] ?? '');
        if ($method === 'sendmail') $transport['method'] = 'sendmail';
        else $transport += ['host' => (string)($site['smtpHost'] ?? ''), 'port' => (int)($site['smtpPort'] ?? 587), 'user' => (string)($site['smtpUsername'] ?? ''),
            'pass' => (string)($site['smtpPassword'] ?? ''), 'enc' => (string)($site['smtpEncryption'] ?? 'tls')];
    }
    if (!filter_var($transport['fromEmail'], FILTER_VALIDATE_EMAIL)) return ['error' => 'newsletter.error_sender'] + $transport;
    if (nibblyNewsletterBaseUrl() === '') return ['error' => 'newsletter.error_site_url'] + $transport;
    $transport['ok'] = true;
    return $transport;
}

/**
 * Sends one rendered mail and notes it in the mail log. $type: campaign, confirm or test.
 * Returns '' on success, else the error text.
 */
function nibblyNewsletterDeliver(array $transport, string $to, array $mail, string $type = 'campaign', string $campaignId = ''): string {
    $error = nibblyNewsletterTransmit($transport, $to, $mail);
    nibblyNewsletterLog($type, $to, (string)$mail['subject'], $error, $campaignId);
    return $error;
}

function nibblyNewsletterLogPath(): string {
    return nibblyNewsletterPath('mail-log.jsonl');
}

/** Appends one line to the mail log; the file keeps the newest entries of the last weeks. */
function nibblyNewsletterLog(string $type, string $to, string $subject, string $error, string $campaignId = ''): void {
    static $enabled = null;
    $enabled = $enabled ?? nibblyNewsletterSettings()['mailLog'];
    if (!$enabled) return;
    $path = nibblyNewsletterLogPath();
    $lock = nibblyJsonLock($path);
    if ($lock === false) return;
    try {
        $entry = ['at' => time(), 'type' => $type, 'to' => $to, 'subject' => mb_substr($subject, 0, 200), 'error' => mb_substr($error, 0, 300)];
        if ($campaignId !== '') $entry['campaign'] = $campaignId;
        file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        // Trim only now and then: the file may grow to twice its target before it is cut back.
        if (filesize($path) > NIBBLY_NL_LOG_ENTRIES * 2 * 220) {
            $oldest = time() - NIBBLY_NL_LOG_DAYS * 86400;
            $lines = array_slice(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -NIBBLY_NL_LOG_ENTRIES);
            $lines = array_values(array_filter($lines, static fn($line) => (int)(json_decode($line, true)['at'] ?? 0) >= $oldest));
            file_put_contents($path, $lines ? implode("\n", $lines) . "\n" : '');
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Newest log entries first, at most $limit and none older than the retention time. */
function nibblyNewsletterLogEntries(int $limit = 500): array {
    $path = nibblyNewsletterLogPath();
    if (!is_file($path)) return [];
    $oldest = time() - NIBBLY_NL_LOG_DAYS * 86400;
    $entries = [];
    foreach (array_reverse(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
        $entry = json_decode($line, true);
        if (!is_array($entry) || (int)($entry['at'] ?? 0) < $oldest) continue;
        $entries[] = $entry;
        if (count($entries) >= $limit) break;
    }
    return $entries;
}

function nibblyNewsletterClearLog(): void {
    foreach (['', '.lock'] as $suffix) @unlink(nibblyNewsletterLogPath() . $suffix);
}

function nibblyNewsletterTransmit(array $transport, string $to, array $mail): string {
    require_once dirname(__DIR__) . '/api/SmtpMailer.php';
    if ($transport['method'] === 'sendmail') {
        [$mimeHeaders, $body] = SmtpMailer::mimeParts($mail['text'], $mail['html']);
        $headers = array_merge([
            'From: =?UTF-8?B?' . base64_encode($transport['fromName']) . '?= <' . $transport['fromEmail'] . '>',
        ], $transport['replyTo'] !== '' ? ['Reply-To: <' . $transport['replyTo'] . '>'] : [], $mimeHeaders, SmtpMailer::cleanHeaders($mail['headers']));
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($mail['subject']) . '?=', $body, implode("\r\n", $headers));
        return $ok ? '' : 'mail() failed';
    }
    $mailer = new SmtpMailer($transport['host'], $transport['port'], $transport['user'], $transport['pass'], $transport['enc']);
    $ok = $mailer->send($to, $mail['subject'], $mail['text'], $transport['fromEmail'], $transport['fromName'], $transport['replyTo'], [], $mail['html'], $mail['headers']);
    return $ok ? '' : ((string)$mailer->getLastError() ?: 'SMTP error');
}

/** The server refused this recipient for good (5xx on RCPT): the address is undeliverable. */
function nibblyNewsletterIsHardBounce(string $error): bool {
    return (bool)preg_match('/RCPT TO rejected for .*: 5\d\d/', $error);
}

/** Sends (or re-sends) the confirmation mail and counts it. */
function nibblyNewsletterSendConfirmation(array $subscriber): string {
    $transport = nibblyNewsletterTransport();
    if (!$transport['ok']) return $transport['error'];
    $error = nibblyNewsletterDeliver($transport, $subscriber['email'], nibblyNewsletterConfirmMail($subscriber), 'confirm');
    if ($error === '') {
        nibblyNewsletterNoteSent(1);
        nibblyNewsletterUpdate('subscribers.json', function (array &$store) use ($subscriber) {
            if (!isset($store['subscribers'][$subscriber['id']])) return false;
            $store['subscribers'][$subscriber['id']]['confirmSent'] = (int)($store['subscribers'][$subscriber['id']]['confirmSent'] ?? 0) + 1;
            $store['subscribers'][$subscriber['id']]['confirmSentAt'] = date('c');
            return true;
        }, ['subscribers' => []]);
    }
    return $error;
}

// ---------------------------------------------------------------------------------------------
// Queue
// ---------------------------------------------------------------------------------------------

function nibblyNewsletterSentLast24h(): int {
    $hours = nibblyNewsletterRead('state.json')['hours'] ?? [];
    $limit = (int)floor(time() / 3600) - 24;
    $sum = 0;
    foreach ((array)$hours as $hour => $count) if ((int)$hour > $limit) $sum += (int)$count;
    return $sum;
}

function nibblyNewsletterNoteSent(int $count): void {
    nibblyNewsletterUpdate('state.json', function (array &$state) use ($count): void {
        $hour = (int)floor(time() / 3600);
        $state['hours'] = array_filter((array)($state['hours'] ?? []), static fn($key) => (int)$key > $hour - 25, ARRAY_FILTER_USE_KEY);
        $state['hours'][$hour] = (int)($state['hours'][$hour] ?? 0) + $count;
    });
}

/** Starts a draft: fixes the recipients and queues them. $sendAt in the future schedules it. */
function nibblyNewsletterStartCampaign(string $id, ?int $sendAt = null): array {
    $campaign = nibblyNewsletterCampaign($id);
    if (!$campaign) throw new InvalidArgumentException('newsletter.error_not_found');
    if (!in_array($campaign['status'], ['draft', 'scheduled'], true)) throw new InvalidArgumentException('newsletter.error_locked');
    if (trim($campaign['subject']) === '' || !$campaign['blocks']) throw new InvalidArgumentException('newsletter.error_empty');
    $transport = nibblyNewsletterTransport();
    if (!$transport['ok']) throw new InvalidArgumentException($transport['error']);
    if ($sendAt !== null && $sendAt > time() + 30) {
        return nibblyNewsletterUpdateCampaign($id, function (array &$campaign) use ($sendAt): void {
            $campaign['status'] = 'scheduled';
            $campaign['sendAt'] = date('c', $sendAt);
            $campaign['updatedAt'] = date('c');
        });
    }
    $recipients = nibblyNewsletterRecipients($campaign);
    if (!$recipients) throw new InvalidArgumentException('newsletter.error_no_recipients');
    $settings = nibblyNewsletterSettings();
    if (!nibblyNewsletterUpdate('campaigns/' . $id . '.rcpt.json', function (array &$data) use ($recipients): void {
        $data = ['queue' => $recipients, 'done' => [], 'attempts' => [], 'links' => []];
    })) throw new RuntimeException('newsletter.error_storage');
    return nibblyNewsletterUpdateCampaign($id, function (array &$campaign) use ($recipients, $settings): void {
        $campaign['status'] = 'sending';
        $campaign['startedAt'] = date('c');
        $campaign['updatedAt'] = date('c');
        $campaign['sendAt'] = null;
        $campaign['trackOpens'] = $settings['trackOpens'];
        $campaign['trackClicks'] = $settings['trackClicks'];
        $campaign['lastError'] = '';
        $campaign['stats'] = ['total' => count($recipients), 'sent' => 0, 'failed' => 0, 'opens' => 0, 'clicks' => 0, 'unsubs' => 0];
    });
}

/**
 * One run of the send worker: due scheduled campaigns start, then up to one batch goes out.
 * Never runs twice at the same time. Returns a summary for the caller.
 */
function nibblyNewsletterRunQueue(?int $maxMails = null): array {
    $summary = ['sent' => 0, 'failed' => 0, 'remaining' => 0, 'limit' => false, 'busy' => false, 'error' => ''];
    $dir = nibblyNewsletterDir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return ['error' => 'newsletter.error_storage'] + $summary;
    $lock = @fopen($dir . '/run.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        return ['busy' => true] + $summary;
    }
    try {
        @set_time_limit(120);
        $settings = nibblyNewsletterSettings();
        $budget = min($maxMails ?? $settings['batchSize'], $settings['batchSize']);
        if ($settings['dailyLimit'] > 0) {
            $left = $settings['dailyLimit'] - nibblyNewsletterSentLast24h();
            if ($left <= 0) $summary['limit'] = true;
            $budget = min($budget, max(0, $left));
        }
        $transport = nibblyNewsletterTransport($settings);
        $subscribers = null;
        foreach (nibblyNewsletterCampaigns() as $campaign) {
            if ($campaign['status'] === 'scheduled' && !empty($campaign['sendAt']) && strtotime($campaign['sendAt']) <= time()) {
                try { $campaign = nibblyNewsletterStartCampaign($campaign['id']); }
                catch (Throwable $error) {
                    nibblyNewsletterUpdateCampaign($campaign['id'], function (array &$c) use ($error): void { $c['status'] = 'paused'; $c['lastError'] = $error->getMessage(); });
                    continue;
                }
            }
            if (($campaign['status'] ?? '') !== 'sending') continue;
            if (!$transport['ok']) { $summary['error'] = $transport['error']; break; }
            $subscribers = $subscribers ?? nibblyNewsletterSubscribers();
            $result = nibblyNewsletterSendBatch($campaign, $subscribers, $transport, $settings, $budget);
            $budget -= $result['sent'];
            foreach (['sent', 'failed', 'remaining'] as $key) $summary[$key] += $result[$key];
            if ($result['error'] !== '') $summary['error'] = $result['error'];
            if ($budget <= 0 && $result['remaining'] > 0 && $settings['dailyLimit'] > 0 && nibblyNewsletterSentLast24h() >= $settings['dailyLimit']) $summary['limit'] = true;
        }
        if ($budget > 0 && $transport['ok']) nibblyNewsletterSendReminders($settings, $budget);
        nibblyNewsletterUpdate('state.json', function (array &$state) use ($summary): void { $state['lastRun'] = date('c'); $state['lastSummary'] = $summary; });
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $summary;
}

function nibblyNewsletterSendBatch(array $campaign, array $subscribers, array $transport, array $settings, int $budget): array {
    $id = $campaign['id'];
    $result = ['sent' => 0, 'failed' => 0, 'remaining' => 0, 'error' => ''];
    $file = 'campaigns/' . $id . '.rcpt.json';
    $data = nibblyNewsletterRead($file, ['queue' => [], 'done' => [], 'attempts' => [], 'links' => []]);
    $queue = array_values((array)($data['queue'] ?? []));
    $consecutive = 0;
    while ($queue && $result['sent'] < $budget) {
        $sid = (string)array_shift($queue);
        $subscriber = $subscribers[$sid] ?? null;
        $outcome = null;
        if (!is_array($subscriber) || ($subscriber['status'] ?? '') !== 'subscribed') {
            $outcome = ['e' => 'skipped'];
        } else {
            $error = nibblyNewsletterDeliver($transport, $subscriber['email'], nibblyNewsletterRender($campaign, $subscriber, ['settings' => $settings]), 'campaign', $id);
            if ($error === '') {
                $outcome = ['t' => time()];
                $result['sent']++;
                $consecutive = 0;
            } elseif (nibblyNewsletterIsHardBounce($error)) {
                $outcome = ['e' => mb_substr($error, 0, 200), 'b' => 1];
                nibblyNewsletterSetStatus($sid, 'bounced');
            } else {
                $consecutive++;
                $attempts = (int)($data['attempts'][$sid] ?? 0) + 1;
                $data['attempts'][$sid] = $attempts;
                $result['error'] = mb_substr($error, 0, 200);
                if ($attempts >= NIBBLY_NL_MAX_ATTEMPTS) $outcome = ['e' => $result['error']];
                else $queue[] = $sid;
            }
        }
        if ($outcome !== null && !isset($outcome['t'])) $result['failed'] += ($outcome['e'] ?? '') === 'skipped' ? 0 : 1;
        // Stored after every mail, so a crash never sends a mail twice.
        nibblyNewsletterUpdate($file, function (array &$stored) use ($queue, $sid, $outcome, $data): void {
            $stored['queue'] = $queue;
            $stored['attempts'] = $data['attempts'];
            $stored['done'] = (array)($stored['done'] ?? []);
            $stored['links'] = (array)($stored['links'] ?? []);
            if ($outcome !== null) { $stored['done'][$sid] = $outcome; unset($stored['attempts'][$sid]); }
        });
        // Three failures in a row point to the connection, not to single addresses.
        if ($consecutive >= 3) break;
    }
    if ($result['sent'] > 0) nibblyNewsletterNoteSent($result['sent']);
    $result['remaining'] = count($queue);
    $broken = $consecutive >= 3;
    nibblyNewsletterUpdateCampaign($id, function (array &$stored) use ($result, $queue, $broken): void {
        $stored['stats']['sent'] = (int)($stored['stats']['sent'] ?? 0) + $result['sent'];
        $stored['stats']['failed'] = (int)($stored['stats']['failed'] ?? 0) + $result['failed'];
        $stored['lastError'] = $result['error'];
        if (!$queue) { $stored['status'] = 'sent'; $stored['finishedAt'] = date('c'); }
        elseif ($broken) $stored['status'] = 'paused';
        $stored['updatedAt'] = date('c');
    });
    return $result;
}

/** Re-sends the confirmation mail to pending subscribers after the configured wait. */
function nibblyNewsletterSendReminders(array $settings, int $budget): void {
    if (!$settings['doubleOptIn'] || $settings['reminders'] < 1) return;
    $wait = $settings['reminderHours'] * 3600;
    foreach (nibblyNewsletterSubscribers() as $subscriber) {
        if ($budget <= 0) return;
        if (($subscriber['status'] ?? '') !== 'pending') continue;
        $sent = (int)($subscriber['confirmSent'] ?? 0);
        $last = strtotime((string)($subscriber['confirmSentAt'] ?? '')) ?: 0;
        if ($sent < 1 || $sent > $settings['reminders'] || time() - $last < $wait) continue;
        if (nibblyNewsletterSendConfirmation($subscriber) !== '') return;
        $budget--;
    }
}

// ---------------------------------------------------------------------------------------------
// Sign-up form for templates and the block
// ---------------------------------------------------------------------------------------------

/**
 * Sign-up form. Options: lists (ids), lang, basePath, names (bool: ask for the first name),
 * button (label). Returns '' while the module is off.
 */
function nibblyNewsletterForm(array $options = []): string {
    if (!nibblyNewsletterModuleEnabled()) return '';
    require_once __DIR__ . '/form-protection.php';
    $settings = nibblyNewsletterSettings();
    $lang = (string)($options['lang'] ?? ($GLOBALS['currentLang'] ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en')));
    $base = (string)($options['basePath'] ?? ($GLOBALS['basePath'] ?? ''));
    if (!preg_match('#^(\.\./)*$|^/([A-Za-z0-9._~-]+/)*$#', $base)) $base = '';
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $s = static fn(string $key): string => htmlspecialchars(nibblyNewsletterString($key, $lang), ENT_QUOTES, 'UTF-8');
    static $count = 0;
    $n = ++$count;
    $lists = implode(',', array_intersect(array_map('strval', (array)($options['lists'] ?? [])), array_column(nibblyNewsletterLists(), 'id')));
    $consent = $settings['consentText'] !== '' ? $settings['consentText'] : nibblyNewsletterString('consent_default', $lang);
    $html = '<form class="nibbly-newsletter-form" method="post" action="' . $e($base) . 'api/newsletter.php?a=subscribe" data-newsletter-form>'
        . nibblyFormProtectionFields('newsletter')
        . '<input type="hidden" name="lang" value="' . $e($lang) . '"><input type="hidden" name="lists" value="' . $e($lists) . '">';
    if (!empty($options['names'])) {
        $html .= '<p class="nibbly-newsletter-field"><label for="nl-first-' . $n . '">' . $s('first_name') . '</label>'
            . '<input type="text" id="nl-first-' . $n . '" name="firstName" autocomplete="given-name" maxlength="120"></p>';
    }
    $html .= '<p class="nibbly-newsletter-field"><label for="nl-email-' . $n . '">' . $s('email') . '</label>'
        . '<input type="email" id="nl-email-' . $n . '" name="email" required autocomplete="email" maxlength="254"></p>'
        . '<p class="nibbly-newsletter-consent"><label><input type="checkbox" name="consent" value="1" required> <span>' . $e($consent) . '</span></label></p>'
        . '<p class="nibbly-newsletter-actions"><button type="submit">' . $e((string)($options['button'] ?? '') ?: nibblyNewsletterString('subscribe', $lang)) . '</button></p>'
        . '<p class="nibbly-newsletter-message" role="status" aria-live="polite"></p></form>';
    if ($n === 1) {
        // Progressive enhancement: without JavaScript the form posts to a result page.
        $html .= '<script>document.addEventListener("submit",function(e){var f=e.target;if(!f.matches||!f.matches("[data-newsletter-form]"))return;e.preventDefault();'
            . 'var m=f.querySelector(".nibbly-newsletter-message"),b=f.querySelector("button[type=submit]");b.disabled=true;'
            . 'fetch(f.action,{method:"POST",body:new FormData(f),headers:{"Accept":"application/json"}}).then(function(r){return r.json()}).then(function(d){'
            . 'm.textContent=d.message||"";m.className="nibbly-newsletter-message "+(d.success?"is-success":"is-error");'
            . 'if(d.token)f.querySelector("[name=form_token]").value=d.token;if(d.success){f.reset();f.classList.add("is-done")}})'
            . '.catch(function(){m.textContent=' . json_encode(nibblyNewsletterString('try_again', $lang)) . ';m.className="nibbly-newsletter-message is-error"})'
            . '.then(function(){b.disabled=false})});</script>';
    }
    return $html;
}
