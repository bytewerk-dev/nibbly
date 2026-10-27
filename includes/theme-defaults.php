<?php
/**
 * Dashboard theme defaults (Settings → Design), shared by the dashboard, the
 * login page, the frontend admin bar, the settings API and the setup wizard.
 *
 * A new installation starts with Pop and the palette its preset card applies
 * (admin/dashboard/scripts/settings.php → THEME_PRESET_DEFAULTS.pop). A theme
 * saved without a "preset" key comes from an older installation (up to 2.5.1
 * the setup wizard wrote only the colours); it keeps Soft and the former blue,
 * so an update never changes the look of a site.
 */

const NIBBLY_THEME_PRESETS = ['soft', 'classic', 'paper', 'studio', 'console', 'pop'];
const NIBBLY_THEME_DEFAULT_PRESET = 'pop';

/** Theme of a new installation: Pop with its own colours, font and radius. */
function nibblyThemeDefaults(): array {
    return [
        'adminTheme' => 'light',
        'preset' => NIBBLY_THEME_DEFAULT_PRESET,
        'density' => 'auto',
        'font' => 'atkinson',
        'fontSize' => 'default',
        'primaryColor' => '#3d8ee9',
        'accentColor' => '',
        'sidebarBg' => '',
        'darkPrimaryColor' => '',
        'darkAccentColor' => '',
        'darkSidebarBg' => '',
        'dangerColor' => '#f0625d',
        'warningColor' => '#ef9524',
        'successColor' => '#27b39a',
        'buttonGlow' => true,
        'buttonRadius' => 24,
    ];
}

/** Defaults for a theme saved without a preset: Soft as it looked up to 2.5. */
function nibblyThemeLegacyDefaults(): array {
    return array_replace(nibblyThemeDefaults(), [
        'preset' => 'soft',
        'font' => 'system',
        'primaryColor' => '#3858e9',
        'dangerColor' => '',
        'warningColor' => '',
        'successColor' => '',
        'buttonRadius' => 6,
    ]);
}

/** The defaults that apply to a stored theme array (content/settings.json → theme). */
function nibblyThemeDefaultsFor($saved): array {
    return (is_array($saved) && $saved !== [] && !array_key_exists('preset', $saved))
        ? nibblyThemeLegacyDefaults()
        : nibblyThemeDefaults();
}

/** A stored theme merged over the defaults that apply to it. */
function nibblyThemeSettings($saved): array {
    return array_replace(nibblyThemeDefaultsFor($saved), is_array($saved) ? $saved : []);
}

/** A valid preset name, else the default one. */
function nibblyThemePreset($value): string {
    return in_array($value, NIBBLY_THEME_PRESETS, true) ? $value : NIBBLY_THEME_DEFAULT_PRESET;
}

/**
 * The saved base colour and the optional overrides as custom properties on
 * :root; css/nibbly-admin-tokens.css derives everything else per preset.
 * Browsers without relative colour syntax get the older color-mix()
 * derivation inside @supports not (...); the semantic shades (danger,
 * warning, success) mix from --nb-*-base in the token file's static block,
 * so the saved colours reach those browsers as well. Used by the dashboard
 * and the guide; admin/dashboard/scripts/settings.php → themeFallbackCss()
 * mirrors this block for the live preview.
 */
function nibblyThemeServerStyle(array $theme): string {
    $_t = nibblyThemeSettings($theme);
    $_pcLight = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$_t['primaryColor']) ? strtolower((string)$_t['primaryColor']) : nibblyThemeDefaults()['primaryColor'];
    $_acRaw = strtolower((string)($_t['accentColor'] ?? ''));
    // An accent equal to the base colour is the old default, not a deliberate override
    $_acLight = (preg_match('/^#[0-9a-fA-F]{6}$/', $_acRaw) && $_acRaw !== $_pcLight) ? $_acRaw : $_pcLight;
    $_sbLightOverride = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_t['sidebarBg'] ?? '')) ? strtolower((string)$_t['sidebarBg']) : '';
    $_sbDarkOverride = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_t['darkSidebarBg'] ?? '')) ? strtolower((string)$_t['darkSidebarBg']) : '';
    $_pcDark = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_t['darkPrimaryColor'] ?? '')) ? strtolower((string)$_t['darkPrimaryColor']) : $_pcLight;
    $_acDark = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_t['darkAccentColor'] ?? '')) ? strtolower((string)$_t['darkAccentColor']) : $_acLight;
    $_semantic = [];
    foreach (['dangerColor' => '--nb-danger-base', 'warningColor' => '--nb-warning-base', 'successColor' => '--nb-success-base'] as $_semKey => $_semVar) {
        $_semRaw = strtolower((string)($_t[$_semKey] ?? ''));
        if (preg_match('/^#[0-9a-f]{6}$/', $_semRaw)) $_semantic[$_semVar] = $_semRaw;
    }
    $_sbLight = $_sbLightOverride !== '' ? $_sbLightOverride : "color-mix(in srgb, {$_pcLight} 12%, white)";
    $_sbDark = $_sbDarkOverride !== '' ? $_sbDarkOverride : "color-mix(in srgb, {$_pcDark} 10%, #0b0d12)";
    ob_start();
    ?>
    <style id="nb-theme-server">
    :root {
        --nb-base: <?php echo $_pcLight; ?>;
        --nb-accent-base: <?php echo $_acLight; ?>;
<?php foreach ($_semantic as $_semVar => $_semHex): ?>
        <?php echo $_semVar; ?>: <?php echo $_semHex; ?>;
<?php endforeach; ?>
    }
    <?php if ($_sbLightOverride !== ''): ?>
    :root, [data-site-theme="light"] { --nb-sidebar-bg: <?php echo $_sbLightOverride; ?>; --nb-topbar-bg: <?php echo $_sbLightOverride; ?>; }
    <?php endif; ?>
    <?php if ($_sbDarkOverride !== ''): ?>
    [data-site-theme="dark"] { --nb-sidebar-bg: <?php echo $_sbDarkOverride; ?>; --nb-topbar-bg: <?php echo $_sbDarkOverride; ?>; }
    <?php endif; ?>
    @supports not (color: oklch(from red l c h)) {
    :root,
    [data-site-theme="light"] {
        --nb-primary: <?php echo $_pcLight; ?>;
        --nb-primary-subtle: color-mix(in srgb, <?php echo $_pcLight; ?> 8%, transparent);
        --nb-primary-muted: color-mix(in srgb, <?php echo $_pcLight; ?> 15%, transparent);
        --nb-primary-medium: color-mix(in srgb, <?php echo $_pcLight; ?> 30%, transparent);
        --nb-primary-btn: radial-gradient(ellipse at 50% 0%, color-mix(in srgb, <?php echo $_pcLight; ?> 70%, white) 0%, <?php echo $_pcLight; ?> 70%);
        --nb-primary-btn-hover: radial-gradient(ellipse at 50% 0%, color-mix(in srgb, <?php echo $_pcLight; ?> 50%, white) 0%, <?php echo $_pcLight; ?> 70%);
        --nb-brand: <?php echo $_acLight; ?>;
        --nb-brand-light: color-mix(in srgb, <?php echo $_acLight; ?> 65%, white);
        --nb-brand-dark: color-mix(in srgb, <?php echo $_acLight; ?> 80%, black);
        --nb-brand-subtle: color-mix(in srgb, <?php echo $_acLight; ?> 12%, transparent);
        --nb-sidebar-bg: <?php echo $_sbLight; ?>;
        --nb-bg: color-mix(in srgb, <?php echo $_sbLight; ?> 55%, white);
        --nb-bg-elevated: color-mix(in srgb, <?php echo $_sbLight; ?> 18%, white);
        --nb-bg-sunken: color-mix(in srgb, <?php echo $_sbLight; ?> 82%, white);
        --nb-bg-hover: color-mix(in srgb, <?php echo $_sbLight; ?> 68%, white);
        --nb-border: color-mix(in srgb, <?php echo $_sbLight; ?> 55%, #9ca3af);
        --nb-border-strong: color-mix(in srgb, <?php echo $_sbLight; ?> 48%, #7b8492);
    }
    [data-site-theme="dark"] {
        --nb-primary: <?php echo $_pcDark; ?>;
        --nb-primary-subtle: color-mix(in srgb, <?php echo $_pcDark; ?> 12%, transparent);
        --nb-primary-muted: color-mix(in srgb, <?php echo $_pcDark; ?> 22%, transparent);
        --nb-primary-medium: color-mix(in srgb, <?php echo $_pcDark; ?> 38%, transparent);
        --nb-primary-btn: radial-gradient(ellipse at 50% 0%, color-mix(in srgb, <?php echo $_pcDark; ?> 70%, white) 0%, <?php echo $_pcDark; ?> 70%);
        --nb-primary-btn-hover: radial-gradient(ellipse at 50% 0%, color-mix(in srgb, <?php echo $_pcDark; ?> 50%, white) 0%, <?php echo $_pcDark; ?> 70%);
        --nb-brand: <?php echo $_acDark; ?>;
        --nb-brand-light: color-mix(in srgb, <?php echo $_acDark; ?> 70%, white);
        --nb-brand-dark: color-mix(in srgb, <?php echo $_acDark; ?> 80%, black);
        --nb-brand-subtle: color-mix(in srgb, <?php echo $_acDark; ?> 18%, transparent);
        --nb-sidebar-bg: <?php echo $_sbDark; ?>;
        --nb-bg: color-mix(in srgb, <?php echo $_sbDark; ?> 78%, #202428);
        --nb-bg-elevated: color-mix(in srgb, <?php echo $_sbDark; ?> 58%, #2a2d31);
        --nb-bg-sunken: color-mix(in srgb, <?php echo $_sbDark; ?> 88%, #030405);
        --nb-bg-hover: color-mix(in srgb, <?php echo $_sbDark; ?> 42%, #34383e);
        --nb-border: color-mix(in srgb, <?php echo $_sbDark; ?> 55%, #5a6472);
        --nb-border-strong: color-mix(in srgb, <?php echo $_sbDark; ?> 44%, #717b88);
    }
    }
    </style>
    <?php
    return (string)ob_get_clean();
}
