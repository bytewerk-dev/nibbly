<?php
/**
 * Default Navigation Configuration
 *
 * This file provides a minimal fallback when no nav-config.php exists.
 * The Setup Wizard generates a full nav-config.php with your site's pages.
 *
 * $PAGE_MAPPING: Maps page slugs to paths for language switching.
 * $NAV_ITEMS: Navigation items per language.
 */

$defaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';

$SITE_LANGUAGES = [
    $defaultLang => ucfirst($defaultLang),
];

$PAGE_MAPPING = [
    'home' => [
        $defaultLang => '.',
    ],
];

$NAV_ITEMS = [
    $defaultLang => [
        ['href' => '.', 'label' => 'Home', 'page' => 'home'],
    ],
];
