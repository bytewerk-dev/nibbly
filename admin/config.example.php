<?php
/**
 * Nibbly CMS - Configuration
 * Copy this file to config.php and adjust the values for your site.
 * User accounts are managed in content/users.json (created by the setup wizard).
 */

// ============================================================
// VERSION
// ============================================================

define('NIBBLY_VERSION', '1.0.0');

// ============================================================
// SITE SETTINGS
// ============================================================

define('SITE_NAME', 'My Website');

// ============================================================
// LANGUAGES
// ============================================================
// Primary language: pages live at root (no URL prefix).
// Additional languages: pages live under /{code}/ (e.g. /de/).
// ISO 639-1 codes: de, en, fr, es, it, pt, nl, etc.

define('SITE_LANG_DEFAULT', 'en');

$SITE_LANGUAGES = [
    'en' => 'English',
    'de' => 'Deutsch',
    'es' => 'Español',
];

// ============================================================
// PATHS (relative to webroot)
// ============================================================

define('CONTENT_PATH', __DIR__ . '/../content/pages/');
define('PAGES_TRASH_PATH', __DIR__ . '/../content/pages-trash/');
define('BACKUP_PATH', __DIR__ . '/../backups/');
define('EVENTS_PATH', __DIR__ . '/../content/events.json');
define('IMAGES_PATH', __DIR__ . '/../assets/images/');
define('IMAGES_TRASH_PATH', __DIR__ . '/../assets/images-trash/');
define('AUDIO_PATH', __DIR__ . '/../assets/audio/');
define('AUDIO_TRASH_PATH', __DIR__ . '/../assets/audio-trash/');
define('SETTINGS_PATH', __DIR__ . '/../content/settings.json');
define('USERS_PATH', __DIR__ . '/../content/users.json');

// ============================================================
// LIMITS
// ============================================================

define('MAX_BACKUPS', 30);
define('SESSION_LIFETIME', 3600); // 1 hour

