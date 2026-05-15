<?php
/**
 * Output-buffer based email obfuscation for public frontend pages.
 */

function nibblyEmailObfuscationEnabled(): bool {
    $settingsPath = dirname(__DIR__) . '/content/settings.json';
    if (!is_file($settingsPath)) {
        return false;
    }
    $settings = json_decode((string)file_get_contents($settingsPath), true);
    return is_array($settings) && !empty($settings['privacy']['emailObfuscation']);
}

function nibblyEmailObfuscatorIsAdmin(): bool {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
    return !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function nibblyEmailFallbackText(string $email): string {
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return htmlspecialchars($local . ' [at] ' . str_replace('.', ' [dot] ', $domain), ENT_QUOTES, 'UTF-8');
}

function nibblyEmailDataAttrs(string $email, string $query = ''): string {
    $attrs = ' data-nibbly-email="' . htmlspecialchars(base64_encode($email), ENT_QUOTES, 'UTF-8') . '"';
    if ($query !== '') {
        $attrs .= ' data-nibbly-email-query="' . htmlspecialchars(base64_encode($query), ENT_QUOTES, 'UTF-8') . '"';
    }
    return $attrs;
}

function nibblyEmailObfuscateHtml(string $html): string {
    $placeholders = [];
    $html = preg_replace_callback('#<(script|style|textarea|pre|code)\b[^>]*>.*?</\1>#is', function ($m) use (&$placeholders) {
        $key = '%%NIBBLY_SKIP_' . count($placeholders) . '%%';
        $placeholders[$key] = $m[0];
        return $key;
    }, $html);

    $emailPattern = '[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}';

    $html = preg_replace_callback('#<a\b([^>]*?)\bhref=(["\'])mailto:([^"\']+)\2([^>]*)>(.*?)</a>#is', function ($m) {
        $before = $m[1];
        $quote = $m[2];
        $mailto = html_entity_decode($m[3], ENT_QUOTES, 'UTF-8');
        $after = $m[4];
        $label = $m[5];
        $parts = explode('?', $mailto, 2);
        $email = rawurldecode($parts[0]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $m[0];
        }
        $query = isset($parts[1]) ? '?' . $parts[1] : '';
        $attrs = preg_replace('#\s*href=' . preg_quote($quote, '#') . 'mailto:[^"\']+' . preg_quote($quote, '#') . '#i', '', $before . $after);
        $fallback = preg_match('/^\s*' . preg_quote(htmlspecialchars($email, ENT_QUOTES, 'UTF-8'), '/') . '\s*$/i', trim($label))
            ? nibblyEmailFallbackText($email)
            : $label;
        return '<a href="#"' . $attrs . nibblyEmailDataAttrs($email, $query) . '>' . $fallback . '</a>';
    }, $html);

    $html = preg_replace_callback('/(?<![\/\w.="\'-])(' . $emailPattern . ')(?![\w-])/i', function ($m) {
        $email = $m[1];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $m[0];
        }
        return '<span' . nibblyEmailDataAttrs($email) . '>' . nibblyEmailFallbackText($email) . '</span>';
    }, $html);

    if (!empty($placeholders)) {
        $html = strtr($html, $placeholders);
    }

    return $html;
}

function nibblyStartEmailObfuscation(): void {
    static $started = false;
    if ($started || nibblyEmailObfuscatorIsAdmin() || !nibblyEmailObfuscationEnabled()) {
        return;
    }
    $started = true;
    ob_start('nibblyEmailObfuscateHtml');
}
