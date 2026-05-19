<?php
/**
 * Core version metadata.
 *
 * This file is part of the updateable core. Site-local admin/config.php files
 * may contain an older NIBBLY_VERSION value for backwards compatibility.
 */

if (!defined('NIBBLY_CORE_VERSION')) {
    define('NIBBLY_CORE_VERSION', '1.3.1');
}

if (!function_exists('nibblyVersion')) {
    function nibblyVersion(): string {
        if (defined('NIBBLY_CORE_VERSION')) {
            return NIBBLY_CORE_VERSION;
        }

        return defined('NIBBLY_VERSION') ? NIBBLY_VERSION : 'dev';
    }
}
