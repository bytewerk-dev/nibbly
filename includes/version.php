<?php
/**
 * Core version metadata.
 *
 * The canonical release value lives in the repository root VERSION file.
 * Site-local admin/config.php files may contain an older NIBBLY_VERSION value
 * for backwards compatibility, but core UIs should call nibblyVersion().
 */

if (!function_exists('nibblyReadVersionFile')) {
    function nibblyReadVersionFile(): ?string {
        $versionFile = dirname(__DIR__) . '/VERSION';
        if (!is_readable($versionFile)) {
            return null;
        }

        $version = trim((string) file_get_contents($versionFile));
        return $version !== '' ? $version : null;
    }
}

if (!function_exists('nibblyVersion')) {
    function nibblyVersion(): string {
        if (defined('NIBBLY_CORE_VERSION')) {
            return NIBBLY_CORE_VERSION;
        }

        $version = nibblyReadVersionFile();
        if ($version !== null) {
            return $version;
        }

        return defined('NIBBLY_VERSION') ? NIBBLY_VERSION : 'dev';
    }
}

if (!defined('NIBBLY_CORE_VERSION')) {
    define('NIBBLY_CORE_VERSION', nibblyVersion());
}
