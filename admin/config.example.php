<?php
/**
 * Nibbly CMS - Configuration
 * Copy this file to config.php and adjust the values for your site.
 * User accounts are managed in content/users.json (created by the setup wizard).
 */

// ============================================================
// VERSION
// ============================================================

define('NIBBLY_VERSION', '1.3.0');

// Default favicon path used as fallback by the admin UI before settings.json
// values are applied. Override in settings.json -> "favicon".
define('NIBBLY_DEFAULT_FAVICON', '/assets/images/favicon.svg');

// ============================================================
// SITE SETTINGS
// ============================================================

define('SITE_NAME', 'My Website');
// Optional stable identifier for backup filenames and remote folders.
// If omitted, Nibbly derives it from the request host or hosting path.
// define('NIBBLY_SITE_DOMAIN', 'example.com');

// Development login. Existing admin users can log in with password "dev" only
// from loopback hosts (localhost, 127.0.0.1, ::1). This is ignored on non-local
// hosts even if accidentally left enabled. Set false to disable it locally too.
define('NIBBLY_DEV_LOGIN', true);

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
define('VIDEO_PATH', __DIR__ . '/../assets/videos/');
define('VIDEO_TRASH_PATH', __DIR__ . '/../assets/videos-trash/');
define('DOCUMENTS_PATH', __DIR__ . '/../assets/documents/');
define('DOCUMENTS_TRASH_PATH', __DIR__ . '/../assets/documents-trash/');
define('SETTINGS_PATH', __DIR__ . '/../content/settings.json');
define('USERS_PATH', __DIR__ . '/../content/users.json');

// ============================================================
// LIMITS
// ============================================================

define('MAX_BACKUPS', 30);
define('SESSION_LIFETIME', 3600); // 1 hour

// ============================================================
// REMOTE BACKUP OAUTH
// ============================================================
// Public Nibbly OAuth broker for Dropbox connect flows. The broker only
// handles OAuth codes/tokens; backup ZIP files upload directly from this
// installation to the selected remote target.
define('NIBBLY_AUTH_BROKER_URL', 'https://auth.nibbly.dev');
