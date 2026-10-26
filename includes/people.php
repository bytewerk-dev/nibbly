<?php
/** Shared, reusable people profiles. No user accounts or congress-specific data. */
require_once __DIR__ . '/json-store.php';
require_once __DIR__ . '/html-sanitizer.php';

function nibblyPeoplePath(): string { return dirname(__DIR__) . '/content/people.json'; }
function nibblyPeopleRead(): array {
    $path = nibblyPeoplePath();
    if (!is_file($path)) return ['people' => []];
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data) || !is_array($data['people'] ?? null)) throw new RuntimeException('People storage is damaged');
    return $data;
}
function nibblyPeopleLanguages(): array {
    global $SITE_LANGUAGES;
    return array_keys(is_array($SITE_LANGUAGES ?? null) && $SITE_LANGUAGES ? $SITE_LANGUAGES : [(defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en') => '']);
}
function nibblyPersonText(array $person, string $field, string $lang = ''): string {
    $default = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $lang = $lang ?: ($GLOBALS['currentLang'] ?? $default);
    return (string)($person['translations'][$lang][$field] ?? $person['translations'][$default][$field] ?? '');
}
function nibblyPersonNormalize(array $input, array $existing = []): array {
    $out = $existing;
    $out['id'] = $existing['id'] ?? ('person_' . bin2hex(random_bytes(8)));
    $out['name'] = trim(strip_tags(is_string($input['name'] ?? null) ? $input['name'] : ''));
    if ($out['name'] === '' || mb_strlen($out['name']) > 200) throw new InvalidArgumentException('people.name_required');
    $status = $input['status'] ?? 'draft';
    if (!in_array($status, ['draft', 'published'], true)) throw new InvalidArgumentException('people.invalid');
    $out['status'] = $status;
    // An empty order field means the default position 0
    if (($input['order'] ?? '') === '') $input['order'] = 0;
    if (filter_var($input['order'], FILTER_VALIDATE_INT) === false || !is_array($input['groups'] ?? [])) throw new InvalidArgumentException('people.invalid');
    $out['order'] = max(-10000, min(10000, (int)($input['order'] ?? 0)));
    $out['groups'] = [];
    foreach (is_array($input['groups'] ?? null) ? $input['groups'] : [] as $group) {
        if (!is_string($group) || mb_strlen($group) > 80) throw new InvalidArgumentException('people.invalid');
        $group = trim(strip_tags($group));
        if ($group !== '') $out['groups'][] = $group;
    }
    $out['groups'] = array_values(array_unique($out['groups']));
    if (count($out['groups']) > 30) throw new InvalidArgumentException('people.invalid');
    foreach (['photo', 'alt', 'credit', 'website', 'email', 'phone'] as $key) {
        $value = $input[$key] ?? '';
        if (!is_string($value) || strlen($value) > 2000) throw new InvalidArgumentException('people.invalid');
        $value = trim(strip_tags($value));
        if (in_array($key, ['photo','website'], true) && $value !== '') {
            // No active schemes, protocol-relative URLs or traversal. Local assets and HTTPS links work in subdirectories.
            if (preg_match('/[\x00-\x20\\\\]/', $value) || str_starts_with($value, '//') || preg_match('~(^|/)\.\.(/|$)~', rawurldecode($value)) || (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) && !preg_match('~^https?://~i', $value))) throw new InvalidArgumentException('people.invalid_url');
        }
        if ($key === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('people.invalid_email');
        $out[$key] = $value;
    }
    $out['translations'] = $existing['translations'] ?? [];
    $translations = $input['translations'] ?? [];
    if (!is_array($translations)) throw new InvalidArgumentException('people.invalid');
    foreach ($translations as $lang => $fields) {
        if (!in_array($lang, nibblyPeopleLanguages(), true) || !is_array($fields)) throw new InvalidArgumentException('people.invalid');
        foreach (['role','organization','summary','biography','quote'] as $field) {
            if (!array_key_exists($field, $fields)) continue;
            if (!is_string($fields[$field]) || strlen($fields[$field]) > 100000) throw new InvalidArgumentException('people.invalid');
            $out['translations'][$lang][$field] = $field === 'biography' ? nibblySanitizeRichHtml($fields[$field]) : trim(strip_tags($fields[$field]));
        }
    }
    $out['updatedAt'] = date('c');
    $out['createdAt'] = $existing['createdAt'] ?? $out['updatedAt'];
    unset($out['trashedAt'], $out['previousStatus']);
    return $out;
}
/** Public helper deliberately returns published records only, even for logged-in visitors. */
function nibblyPeople(string $group = ''): array {
    try { $records = nibblyPeopleRead()['people']; } catch (RuntimeException $e) { return []; }
    $records = array_values(array_filter($records, fn($p) => is_array($p) && ($p['status'] ?? '') === 'published' && ($group === '' || in_array($group, $p['groups'] ?? [], true))));
    usort($records, fn($a,$b) => (($a['order'] ?? 0) <=> ($b['order'] ?? 0)) ?: strnatcasecmp($a['name'], $b['name']));
    return $records;
}
/** URL slug from a name: lower case, umlauts spelled out, other characters as hyphens. */
function nibblyPeopleSlugify(string $name): string {
    $name = strtr(mb_strtolower($name), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    // Other accents are dropped: "josé" becomes "jose"
    if (class_exists('Normalizer')) $name = (string)preg_replace('/\p{Mn}+/u', '', (string)Normalizer::normalize($name, Normalizer::FORM_D));
    $name = strtr($name, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ł' => 'l', 'ø' => 'o', 'đ' => 'd', 'æ' => 'ae', 'œ' => 'oe', 'ı' => 'i']);
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'person';
}
/** The slug of a profile's own page: stored on save, derived from the name for older records. */
function nibblyPersonSlug(array $person): string {
    return (string)($person['slug'] ?? '') ?: nibblyPeopleSlugify((string)($person['name'] ?? ''));
}
/** A slug for $person that no other profile uses yet ("alex-demo", "alex-demo-2", …). */
function nibblyPersonUniqueSlug(array $person, array $people): string {
    $base = nibblyPeopleSlugify((string)($person['name'] ?? ''));
    $taken = [];
    foreach ($people as $other) if (is_array($other) && ($other['id'] ?? null) !== ($person['id'] ?? null)) $taken[nibblyPersonSlug($other)] = true;
    for ($slug = $base, $n = 2; isset($taken[$slug]); $n++) $slug = $base . '-' . $n;
    return $slug;
}
/** Site-relative URL of a profile's own page: "people/alex-demo" or "en/people/alex-demo". */
function nibblyPersonPath(array $person, string $lang = ''): string {
    $default = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    $lang = $lang ?: ($GLOBALS['currentLang'] ?? $default);
    return ($lang === $default ? '' : $lang . '/') . 'people/' . nibblyPersonSlug($person);
}
/** A published profile by the slug of its page, or null. */
function nibblyPersonBySlug(string $slug): ?array {
    foreach (nibblyPeople() as $person) if (nibblyPersonSlug($person) === $slug) return $person;
    return null;
}
function nibblyPeopleBlockOptions(): array {
    $options = [['value' => '', 'label' => t('people.choose')]];
    // Include drafts in authenticated editing only; frontend output still excludes them.
    try { $records = function_exists('isAdminLoggedIn') && isAdminLoggedIn() ? nibblyPeopleRead()['people'] : nibblyPeople(); } catch (RuntimeException $e) { $records = []; }
    foreach ($records as $person) {
        if (($person['status'] ?? '') === 'trash') continue;
        $options[] = ['value' => $person['id'], 'label' => $person['name'] . (($person['status'] ?? '') === 'draft' ? ' (' . t('people.draft') . ')' : '')];
    }
    return $options;
}
function nibblyPeopleAsset(string $url): string {
    if ($url === '' || preg_match('~^https?://~i', $url)) return $url;
    return rtrim($GLOBALS['basePath'] ?? '', '/') . '/' . ltrim($url, '/');
}
function nibblyRenderPerson(array $person, string $lang = '', bool $full = true): string {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $html = '<article class="person-card">';
    if (!empty($person['photo'])) {
        $src = nibblySanitizeHref(nibblyPeopleAsset($person['photo']));
        $html .= '<figure class="person-card__portrait"><img loading="lazy" src="'.$e($src).'" alt="'.$e($person['alt'] ?: $person['name']).'">';
        if (!empty($person['credit'])) $html .= '<figcaption>'.$e($person['credit']).'</figcaption>';
        $html .= '</figure>';
    }
    $href = rtrim($GLOBALS['basePath'] ?? '', '/');
    $href = ($href === '' ? '' : $href . '/') . nibblyPersonPath($person, $lang);
    $html .= '<div class="person-card__body"><h3><a href="'.$e($href).'">'.$e($person['name']).'</a></h3>';
    $role = array_filter([nibblyPersonText($person,'role',$lang), nibblyPersonText($person,'organization',$lang)], fn($s) => $s !== '');
    if ($role) $html .= '<p class="person-card__role">'.$e(implode(' · ',$role)).'</p>';
    $summary = nibblyPersonText($person,'summary',$lang);
    if ($summary !== '') $html .= '<p>'.$e($summary).'</p>';
    if ($full) {
        $html .= '<div class="person-card__biography">'.nibblySanitizeRichHtml(nibblyPersonText($person,'biography',$lang)).'</div>';
        $quote = nibblyPersonText($person,'quote',$lang);
        if ($quote !== '') $html .= '<blockquote>'.$e($quote).'</blockquote>';
    }
    $links = [];
    if (!empty($person['website'])) $links[] = '<a href="'.$e(nibblySanitizeHref(nibblyPeopleAsset($person['website']))).'">'.$e($person['website']).'</a>';
    if (!empty($person['email'])) $links[] = '<a href="mailto:'.$e($person['email']).'">'.$e($person['email']).'</a>';
    if (!empty($person['phone'])) $links[] = '<a href="tel:'.$e(preg_replace('/[^+0-9]/','',$person['phone'])).'">'.$e($person['phone']).'</a>';
    if ($links) $html .= '<div class="person-card__contact">'.implode(' ', $links).'</div>';
    return $html.'</div></article>';
}
