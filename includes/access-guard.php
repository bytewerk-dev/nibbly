<?php
/**
 * Central frontend access controls for maintenance mode and private pages.
 */

function nibblyAccessStartSession(): void {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
}

function nibblyAccessHasSessionCookie(): bool {
    $name = session_name();
    return $name !== '' && !empty($_COOKIE[$name]);
}

function nibblyAccessStartExistingSession(): bool {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }
    if (!nibblyAccessHasSessionCookie() || headers_sent()) {
        return false;
    }
    session_start();
    return session_status() === PHP_SESSION_ACTIVE;
}

function nibblyAccessSiteRoot(): string {
    return dirname(__DIR__);
}

function nibblyAccessSettings(): array {
    $path = nibblyAccessSiteRoot() . '/content/settings.json';
    if (!is_file($path)) {
        return [];
    }
    $settings = json_decode((string)file_get_contents($path), true);
    return is_array($settings) ? $settings : [];
}

function nibblyAccessIsLoggedIn(): bool {
    if (!nibblyAccessStartExistingSession()) {
        return false;
    }
    if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        return false;
    }
    if (defined('SESSION_LIFETIME') && !empty($_SESSION['admin_login_time'])) {
        if (time() - (int)$_SESSION['admin_login_time'] > SESSION_LIFETIME) {
            return false;
        }
    }
    $_SESSION['admin_login_time'] = time();
    return true;
}

function nibblyAccessCurrentPath(): string {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return '/' . ltrim((string)$path, '/');
}

function nibblyAccessIsBackendOrAssetPath(string $path): bool {
    return preg_match('#^/(admin|api|assets|css|js)(/|$)#', $path) === 1
        || preg_match('#^/(favicon\.ico|robots\.txt|sitemap\.xml)$#', $path) === 1;
}

function nibblyAccessVerifySecret(string $secret, string $hash): bool {
    $secret = trim($secret);
    $hash = trim($hash);
    if ($secret === '' || $hash === '') {
        return false;
    }
    if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$argon2')) {
        return password_verify($secret, $hash);
    }
    return hash_equals($hash, hash('sha256', $secret));
}

function nibblyAccessCheckMaintenanceBypass(array $maintenance): bool {
    $param = trim((string)($maintenance['bypassParam'] ?? ''));
    $hasParam = $param !== '' && array_key_exists($param, $_GET);

    if (!$hasParam && !nibblyAccessStartExistingSession()) {
        return false;
    }
    if ($hasParam) {
        nibblyAccessStartSession();
    }

    if (!empty($_SESSION['nibbly_maintenance_bypass'])) {
        return true;
    }

    $hash = trim((string)($maintenance['bypassKeyHash'] ?? ''));
    if (!$hasParam) {
        return false;
    }

    $candidate = (string)$_GET[$param];
    if (!nibblyAccessVerifySecret($candidate, $hash)) {
        return false;
    }

    $_SESSION['nibbly_maintenance_bypass'] = true;
    return true;
}

function nibblyAccessMaintenanceIsActive(array $maintenance): bool {
    if (empty($maintenance['enabled'])) {
        return false;
    }
    $until = trim((string)($maintenance['until'] ?? ''));
    if ($until === '') {
        return true;
    }
    $timestamp = strtotime($until);
    return $timestamp === false || $timestamp > time();
}

function nibblyAccessAssetPath(string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return '';
    }
    return '/' . ltrim($path, '/');
}

function nibblyAccessResolveBrandAsset(array $settings, string $asset): string {
    if ($asset === 'favicon') {
        return nibblyAccessAssetPath((string)($settings['favicon'] ?? '/assets/images/favicon.svg'));
    }
    if ($asset === 'logo') {
        $logo = (string)($settings['branding']['logo'] ?? '');
        return nibblyAccessAssetPath($logo !== '' ? $logo : (string)($settings['favicon'] ?? '/assets/images/favicon.svg'));
    }
    return '';
}

function nibblyAccessCssUrl(string $path): string {
    return str_replace(["\\", "\"", "\n", "\r"], ["\\\\", "\\\"", "", ""], $path);
}

function nibblyAccessImageLayout(string $layout): string {
    if ($layout === 'split') {
        return 'left';
    }
    return in_array($layout, ['none', 'background', 'left', 'right'], true) ? $layout : 'none';
}

function nibblyAccessRgbaFromHex(string $hex, int $opacity): string {
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
        return '';
    }
    $opacity = max(0, min(100, $opacity));
    $red = hexdec(substr($hex, 1, 2));
    $green = hexdec(substr($hex, 3, 2));
    $blue = hexdec(substr($hex, 5, 2));
    $alpha = rtrim(rtrim(sprintf('%.2F', $opacity / 100), '0'), '.');
    return "rgba($red, $green, $blue, $alpha)";
}

function nibblyAccessRenderStandalonePage(array $options): void {
    $status = (int)($options['status'] ?? 503);
    http_response_code($status);
    if ($status === 503 && !empty($options['retryAfter'])) {
        header('Retry-After: ' . (int)$options['retryAfter']);
    }
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow', false);

    $title = htmlspecialchars((string)($options['title'] ?? 'Temporarily unavailable'), ENT_QUOTES, 'UTF-8');
    $text = nl2br(htmlspecialchars((string)($options['text'] ?? ''), ENT_QUOTES, 'UTF-8'));
    $mode = htmlspecialchars((string)($options['mode'] ?? 'maintenance'), ENT_QUOTES, 'UTF-8');
    $until = htmlspecialchars((string)($options['until'] ?? ''), ENT_QUOTES, 'UTF-8');
    $showCountdown = !empty($options['showCountdown']) && $until !== '';
    $brandAsset = nibblyAccessAssetPath((string)($options['brandAssetPath'] ?? ''));
    $image = nibblyAccessAssetPath((string)($options['image'] ?? ''));
    $imageLayout = $image !== '' ? nibblyAccessImageLayout((string)($options['imageLayout'] ?? 'none')) : 'none';
    $bodyClass = 'nb-lock-page';
    if ($imageLayout === 'background') {
        $bodyClass .= ' nb-lock-page--background';
    } elseif ($imageLayout === 'left') {
        $bodyClass .= ' nb-lock-page--split nb-lock-page--image-left';
    } elseif ($imageLayout === 'right') {
        $bodyClass .= ' nb-lock-page--split nb-lock-page--image-right';
    }
    $cssImage = nibblyAccessCssUrl($image);
    $overlayColor = trim((string)($options['overlayColor'] ?? ''));
    $overlayOpacity = (int)($options['overlayOpacity'] ?? 88);
    $overlayRgba = nibblyAccessRgbaFromHex($overlayColor, $overlayOpacity);
    $overlayStyle = $overlayColor !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $overlayColor)
        ? '; --nb-lock-overlay-color: ' . htmlspecialchars($overlayRgba, ENT_QUOTES, 'UTF-8')
        : '';
    ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $title; ?></title>
    <script>
    (function() {
        try {
            var theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.style.colorScheme = theme;
        } catch (e) {
            document.documentElement.setAttribute('data-theme', 'light');
            document.documentElement.style.colorScheme = 'light';
        }
    })();
    </script>
    <style>
        :root { color-scheme: light; --nb-bg: #f7f7f4; --nb-fg: #141414; --nb-muted: #646464; --nb-border: rgba(20,20,20,.14); --nb-error: #b42318; }
        :root[data-theme="dark"] { color-scheme: dark; --nb-bg: #101010; --nb-fg: #f4f4ef; --nb-muted: #b3b3aa; --nb-border: rgba(255,255,255,.18); --nb-error: #ff8a80; }
        :root[data-theme="light"] { color-scheme: light; --nb-bg: #f7f7f4; --nb-fg: #141414; --nb-muted: #646464; --nb-border: rgba(20,20,20,.14); --nb-error: #b42318; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--nb-bg); color: var(--nb-fg); font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body.nb-lock-page--background { background: linear-gradient(var(--nb-lock-overlay-color, color-mix(in srgb, #f7f7f4 88%, transparent)), var(--nb-lock-overlay-color, color-mix(in srgb, #f7f7f4 88%, transparent))), var(--nb-lock-image), var(--nb-bg); background-position: center; background-size: cover; }
        :root[data-theme="dark"] body.nb-lock-page--background { background: linear-gradient(var(--nb-lock-overlay-color, color-mix(in srgb, #101010 82%, transparent)), var(--nb-lock-overlay-color, color-mix(in srgb, #101010 82%, transparent))), var(--nb-lock-image), var(--nb-bg); background-position: center; background-size: cover; }
        main { width: min(680px, calc(100vw - 40px)); padding: 56px 0; }
        .nb-lock-shell { width: 100%; min-height: 100vh; display: grid; place-items: center; }
        .nb-lock-page--split .nb-lock-shell { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
        .nb-lock-media { display: none; width: 100%; height: 100%; min-height: 100vh; background-image: var(--nb-lock-image); background-position: center; background-size: cover; }
        .nb-lock-page--split .nb-lock-media { display: block; }
        .nb-lock-page--image-right .nb-lock-media { grid-column: 2; }
        .nb-lock-page--image-right main { grid-column: 1; grid-row: 1; }
        .nb-lock-page--split main { width: min(560px, calc(100% - 64px)); justify-self: center; }
        .nb-lock-brand { display: block; width: auto; max-width: 168px; max-height: 72px; margin: 0 0 26px; object-fit: contain; }
        .nb-lock-label { display: inline-flex; margin-bottom: 24px; padding: 6px 10px; border: 1px solid var(--nb-border); border-radius: 999px; color: var(--nb-muted); font-size: 13px; letter-spacing: .04em; text-transform: uppercase; }
        h1 { margin: 0 0 18px; font-size: clamp(34px, 6vw, 68px); line-height: .95; letter-spacing: 0; }
        p { margin: 0; color: var(--nb-muted); font-size: clamp(17px, 2vw, 21px); line-height: 1.55; }
        .nb-countdown { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 32px; }
        .nb-countdown span { min-width: 82px; padding: 14px 16px; border: 1px solid var(--nb-border); border-radius: 8px; text-align: center; font-size: 24px; font-weight: 700; }
        .nb-countdown small { display: block; margin-top: 4px; color: var(--nb-muted); font-size: 11px; font-weight: 500; text-transform: uppercase; }
        form { margin-top: 28px; display: grid; gap: 12px; }
        input { width: 100%; min-height: 46px; padding: 0 14px; border: 1px solid var(--nb-border); border-radius: 6px; background: transparent; color: inherit; font: inherit; }
        button { min-height: 46px; border: 0; border-radius: 6px; background: var(--nb-fg); color: var(--nb-bg); font: inherit; font-weight: 700; cursor: pointer; }
        .nb-error { margin-top: 12px; color: var(--nb-error); font-size: 14px; }
        @media (max-width: 760px) {
            .nb-lock-page--split .nb-lock-shell { grid-template-columns: 1fr; }
            .nb-lock-page--split .nb-lock-media { min-height: 34vh; }
            .nb-lock-page--split main { width: min(680px, calc(100vw - 40px)); }
        }
    </style>
</head>
<body class="<?php echo htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8'); ?>" data-nibbly-lock="<?php echo $mode; ?>"<?php echo $imageLayout !== 'none' ? ' style="--nb-lock-image: url(&quot;' . htmlspecialchars($cssImage, ENT_QUOTES, 'UTF-8') . '&quot;)' . $overlayStyle . '"' : ''; ?>>
    <div class="nb-lock-shell">
        <?php if (in_array($imageLayout, ['left', 'right'], true)): ?><div class="nb-lock-media" aria-hidden="true"></div><?php endif; ?>
    <main>
        <?php if ($brandAsset !== ''): ?><img class="nb-lock-brand" src="<?php echo htmlspecialchars($brandAsset, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php endif; ?>
        <div class="nb-lock-label"><?php echo $mode === 'launch' ? 'Launch' : ($mode === 'private' ? 'Privat' : 'Wartung'); ?></div>
        <h1><?php echo $title; ?></h1>
        <?php if ($text): ?><p><?php echo $text; ?></p><?php endif; ?>
        <?php if (!empty($options['form'])): ?>
        <form method="post">
            <input type="password" name="nibbly_page_password" placeholder="Passwort" autocomplete="current-password" required>
            <button type="submit">Freischalten</button>
        </form>
        <?php if (!empty($options['error'])): ?><div class="nb-error"><?php echo htmlspecialchars((string)$options['error'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
        <?php endif; ?>
        <?php if ($showCountdown): ?>
        <div class="nb-countdown" data-countdown-until="<?php echo $until; ?>" aria-live="polite"></div>
        <script>
        (function(){var el=document.querySelector('[data-countdown-until]');if(!el)return;var target=new Date(el.dataset.countdownUntil).getTime();function tick(){var d=Math.max(0,target-Date.now());var s=Math.floor(d/1000),days=Math.floor(s/86400);s-=days*86400;var h=Math.floor(s/3600);s-=h*3600;var m=Math.floor(s/60);s-=m*60;el.innerHTML='<span>'+days+'<small>Tage</small></span><span>'+h+'<small>Std</small></span><span>'+m+'<small>Min</small></span><span>'+s+'<small>Sek</small></span>';if(d>0)setTimeout(tick,1000);}tick();})();
        </script>
        <?php endif; ?>
    </main>
    </div>
</body>
</html>
    <?php
    exit;
}

function nibblyAccessEnforceMaintenance(): void {
    $path = nibblyAccessCurrentPath();
    if (nibblyAccessIsBackendOrAssetPath($path)) {
        return;
    }

    if (nibblyAccessIsLoggedIn()) {
        return;
    }

    $settings = nibblyAccessSettings();
    $maintenance = $settings['access']['maintenance'] ?? [];
    if (!is_array($maintenance) || !nibblyAccessMaintenanceIsActive($maintenance)) {
        return;
    }
    if (nibblyAccessCheckMaintenanceBypass($maintenance)) {
        return;
    }

    $until = trim((string)($maintenance['until'] ?? ''));
    $retryAfter = 0;
    if ($until !== '') {
        $untilTs = strtotime($until);
        if ($untilTs !== false && $untilTs > time()) {
            $retryAfter = min(86400, max(60, $untilTs - time()));
        }
    }

    nibblyAccessRenderStandalonePage([
        'status' => 503,
        'mode' => $maintenance['mode'] ?? 'maintenance',
        'title' => $maintenance['title'] ?? 'Wartungsarbeiten',
        'text' => $maintenance['text'] ?? 'Wir sind in Kürze wieder online.',
        'until' => $until,
        'showCountdown' => !empty($maintenance['showCountdown']),
        'brandAssetPath' => nibblyAccessResolveBrandAsset($settings, (string)($maintenance['brandAsset'] ?? 'none')),
        'image' => $maintenance['image'] ?? '',
        'imageLayout' => $maintenance['imageLayout'] ?? 'none',
        'overlayColor' => $maintenance['overlayColor'] ?? '',
        'overlayOpacity' => $maintenance['overlayOpacity'] ?? 88,
        'retryAfter' => $retryAfter,
    ]);
}

function nibblyAccessPageIsPrivate(array $pageData): bool {
    $visibility = $pageData['visibility'] ?? [];
    return is_array($visibility) && (($visibility['status'] ?? 'public') === 'private');
}

function nibblyAccessEnforcePage(string $contentPage, array $pageData): void {
    if (!nibblyAccessPageIsPrivate($pageData) || nibblyAccessIsLoggedIn()) {
        return;
    }

    nibblyAccessStartSession();
    $sessionKey = 'nibbly_private_page_' . hash('sha256', $contentPage);
    if (!empty($_SESSION[$sessionKey])) {
        return;
    }

    $visibility = $pageData['visibility'];
    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['nibbly_page_password'])) {
        $password = (string)$_POST['nibbly_page_password'];
        $hash = (string)($visibility['passwordHash'] ?? '');
        if ($hash !== '' && password_verify($password, $hash)) {
            $_SESSION[$sessionKey] = true;
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/'));
            exit;
        }
        $error = 'Das Passwort ist nicht korrekt.';
    }

    nibblyAccessRenderStandalonePage([
        'status' => 403,
        'mode' => 'private',
        'title' => $visibility['title'] ?? 'Geschützte Seite',
        'text' => $visibility['text'] ?? 'Bitte gib das Passwort ein, um diese Seite zu öffnen.',
        'form' => true,
        'error' => $error,
    ]);
}

function nibblyAccessEnforceCurrentTemplatePage(?string $contentPage): void {
    require_once __DIR__ . '/page-path.php';
    if (!$contentPage || !nibblyPageIsValidContentKey($contentPage)) {
        return;
    }
    $path = nibblyAccessSiteRoot() . '/content/pages/' . $contentPage . '.json';
    if (!is_file($path)) {
        return;
    }
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) {
        return;
    }
    nibblyAccessEnforcePage($contentPage, $data);
}
