<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    const settingsMobileNav = document.getElementById('settingsMobileNav');
    document.querySelectorAll('.settings-tab-btn[data-settings-tab]').forEach(button => {
        settingsMobileNav?.add(new Option(button.textContent.trim(), button.dataset.settingsTab));
    });
    settingsMobileNav?.addEventListener('change', () => activateSettingsTab(settingsMobileNav.value));
    function loadSettingsTabData(tab) {
        if (tab === 'users' && typeof loadUsers === 'function' && typeof _usersLoaded !== 'undefined' && !_usersLoaded) {
            _usersLoaded = true;
            loadUsers();
        }
        if (tab === 'menus' && typeof loadMenuOrder === 'function' && typeof _menuOrderLoaded !== 'undefined' && !_menuOrderLoaded) {
            _menuOrderLoaded = true;
            loadMenuOrder();
        }
        if (tab === 'forms' && typeof loadFormsAdmin === 'function' && !formsAdminLoaded) {
            formsAdminLoaded = true;
            loadFormsAdmin();
        }
        if (tab === 'ai' && AI_FEATURES_ENABLED && typeof loadAiSettings === 'function') {
            loadAiSettings();
        }
    }

    function activateSettingsTab(tab, options) {
        options = options || {};
        var btn = document.querySelector('.settings-tab-btn[data-settings-tab="' + tab + '"]');
        if (!btn) return;
        document.querySelectorAll('.settings-tab-btn').forEach(function(b) { b.classList.remove('active'); });
        document.querySelectorAll('.settings-panel').forEach(function(p) { p.classList.remove('active'); });
        btn.classList.add('active');
        if (settingsMobileNav) { settingsMobileNav.value = tab; window.nbSelectSync?.(settingsMobileNav); }
        var panel = document.getElementById('settingsPanel-' + tab);
        if (panel) panel.classList.add('active');
        loadSettingsTabData(tab);
        if (!options.silent) updateDashboardHash('settings', tab, !!options.replace);
    }

    document.querySelectorAll('.settings-tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var action = this.getAttribute('data-settings-action');
            if (action === 'backup') {
                switchTab('backup');
                return;
            }
            var tab = this.getAttribute('data-settings-tab');
            if (!tab) return;
            activateSettingsTab(tab);
        });
    });

    async function loadSettings() {
        try {
            const response = await fetch('api.php?action=load-settings');
            const result = await response.json();

            if (result.success) {
                currentSettings = result.data;
                populateSettings(currentSettings);
                applyTheme(currentSettings.theme || {});
                settingsLoaded = true;
            } else {
                showToast(t('toast.error_loading_settings', {message: result.message}), 'error');
            }
        } catch (error) {
            showToast(t('toast.error_loading_settings', {message: error.message}), 'error');
        }
    }

    function populateSettings(settings) {
        // Branding
        var faviconPath = settings.favicon || '/assets/images/favicon.svg';
        document.getElementById('settingsFavicon').value = faviconPath;
        updateFaviconPreview(faviconPath);
        updateClearButton(document.getElementById('settingsFavicon'));

        var faviconPngPath = settings.favicon_png || '';
        document.getElementById('settingsFaviconPng').value = faviconPngPath;
        updateFaviconPngPreview(faviconPngPath);
        updateClearButton(document.getElementById('settingsFaviconPng'));

        var logoPath = settings.branding.logo || '';
        document.getElementById('settingsLogo').value = logoPath;
        document.getElementById('settingsName').value = settings.branding.name || '';
        document.getElementById('settingsShowBranding').checked = settings.branding.showBranding !== false;
        updateLogoPreview(logoPath);
        updateClearButton(document.getElementById('settingsLogo'));

        var adminLogoPath = settings.branding.adminLogo || '';
        document.getElementById('settingsAdminLogo').value = adminLogoPath;
        updateAdminLogoPreview(adminLogoPath);
        updateClearButton(document.getElementById('settingsAdminLogo'));

        var logoDarkPath = settings.branding.logoDark || '';
        document.getElementById('settingsLogoDark').value = logoDarkPath;
        updateLogoDarkPreview(logoDarkPath);
        updateClearButton(document.getElementById('settingsLogoDark'));

        var logoDisplay = settings.branding.logoDisplay || 'both';
        var displayRadio = document.querySelector('input[name="settingsLogoDisplay"][value="' + logoDisplay + '"]');
        if (displayRadio) displayRadio.checked = true;
        updateLogoDisplayVisibility();

        var logoSize = settings.branding.logoSize || 'medium';
        var sizeRadio = document.querySelector('input[name="settingsLogoSize"][value="' + logoSize + '"]');
        if (sizeRadio) sizeRadio.checked = true;

        var defaultOgPath = settings.seo?.defaultOgImage || '';
        var defaultOgInput = document.getElementById('settingsDefaultOgImage');
        if (defaultOgInput) {
            defaultOgInput.value = defaultOgPath;
            updateDefaultOgPreview(defaultOgPath);
            updateClearButton(defaultOgInput);
        }

        // Theme
        document.getElementById('settingsAdminTheme').value = settings.theme.adminTheme || 'light';
        document.querySelectorAll('.theme-option').forEach(function(btn) {
            btn.classList.toggle('selected', btn.dataset.theme === settings.theme.adminTheme);
        });

        // Colors — Light mode
        var primary = settings.theme.primaryColor || '#3858e9';
        var accent = settings.theme.accentColor || '#3858e9';
        document.getElementById('settingsPrimaryColor').value = primary;
        document.getElementById('settingsPrimaryColorPicker').value = primary;
        document.getElementById('settingsAccentColor').value = accent;
        document.getElementById('settingsAccentColorPicker').value = accent;

        // Optional fields — empty means "auto". Show the derived value but mark it as auto.
        var sidebarLight = settings.theme.sidebarBg || '';
        setAutoField('sidebarBg', sidebarLight, deriveSidebarLight(primary));

        // Colors — Dark mode
        var darkPrimary = settings.theme.darkPrimaryColor || '';
        var darkAccent = settings.theme.darkAccentColor || '';
        var darkSidebar = settings.theme.darkSidebarBg || '';
        setAutoField('darkPrimaryColor', darkPrimary, primary);
        setAutoField('darkAccentColor', darkAccent, accent);
        // Dark sidebar derives from the resolved dark primary
        var resolvedDarkPrimary = darkPrimary || primary;
        setAutoField('darkSidebarBg', darkSidebar, deriveSidebarDark(resolvedDarkPrimary));

        // Button style
        var glowCheckbox = document.getElementById('settingsButtonGlow');
        if (glowCheckbox) glowCheckbox.checked = settings.theme.buttonGlow !== false;
        var radiusSlider = document.getElementById('settingsButtonRadius');
        var radiusValue = settings.theme.buttonRadius != null ? settings.theme.buttonRadius : 6;
        if (radiusSlider) {
            radiusSlider.value = radiusValue;
            document.getElementById('settingsButtonRadiusValue').textContent = radiusValue + 'px';
        }
        var dashboardSettings = settings.dashboard || {};
        var itemsPerPageEl = document.getElementById('settingsItemsPerPage');
        if (itemsPerPageEl) itemsPerPageEl.value = clampDashboardPageSize(dashboardSettings.itemsPerPage);
        var iconItemsPerPageEl = document.getElementById('settingsIconItemsPerPage');
        if (iconItemsPerPageEl) iconItemsPerPageEl.value = clampDashboardPageSize(dashboardSettings.iconManagerItemsPerPage);
        var mediaItemsPerPageEl = document.getElementById('settingsMediaItemsPerPage');
        if (mediaItemsPerPageEl) mediaItemsPerPageEl.value = clampMediaPageSize(dashboardSettings.mediaItemsPerPage);

        // Language
        var langSelect = document.getElementById('settingsAdminLanguage');
        if (langSelect) langSelect.value = settings.general?.adminLanguage || '';

        // Frontend-login redirect mode (default: 'auto')
        var loginMode = (settings.general && settings.general.frontendLoginRedirect) || 'auto';
        var modeRadio = document.querySelector('input[name="frontendLoginRedirect"][value="' + loginMode + '"]');
        if (modeRadio) modeRadio.checked = true;
        var loginVisual = settings.login || {};
        var loginBrandEl = document.getElementById('loginBrandAsset');
        if (loginBrandEl) loginBrandEl.value = loginVisual.brandAsset || 'favicon';
        var loginImageLayoutEl = document.getElementById('loginImageLayout');
        if (loginImageLayoutEl) loginImageLayoutEl.value = normalizeVisualImageLayout(loginVisual.imageLayout || 'none');
        var loginOverlayColorEl = document.getElementById('loginOverlayColor');
        if (loginOverlayColorEl) loginOverlayColorEl.value = loginVisual.overlayColor || '#ffffff';
        setOverlayOpacity('loginOverlayOpacity', 'loginOverlayOpacityValue', loginVisual.overlayOpacity, 86);
        updateVisualOverlayVisibility('loginImageLayout', 'loginOverlayColorGroup');
        var loginBoxStyleEl = document.getElementById('loginBoxStyle');
        if (loginBoxStyleEl) loginBoxStyleEl.value = loginVisual.boxStyle || 'card';
        setLoginColorPair('loginBoxColor', loginVisual.boxColor || '#ffffff');
        setLoginColorPair('loginBoxTextColor', loginVisual.boxTextColor || '#111827');
        updateLoginBoxColorVisibility();
        var loginImageEl = document.getElementById('loginImage');
        if (loginImageEl) {
            loginImageEl.value = loginVisual.image || '';
            updateClearButton(loginImageEl);
        }

        // Access / privacy
        var access = settings.access || {};
        var maintenance = access.maintenance || {};
        var enabledEl = document.getElementById('maintenanceEnabled');
        if (enabledEl) enabledEl.checked = !!maintenance.enabled;
        var maintModeEl = document.getElementById('maintenanceMode');
        if (maintModeEl) maintModeEl.value = maintenance.mode || 'maintenance';
        var maintTitleEl = document.getElementById('maintenanceTitle');
        if (maintTitleEl) maintTitleEl.value = maintenance.title || '';
        var maintTextEl = document.getElementById('maintenanceText');
        if (maintTextEl) maintTextEl.value = maintenance.text || '';
        var maintUntilEl = document.getElementById('maintenanceUntil');
        if (maintUntilEl) maintUntilEl.value = (maintenance.until || '').slice(0, 16);
        var maintCountdownEl = document.getElementById('maintenanceCountdown');
        if (maintCountdownEl) maintCountdownEl.checked = !!maintenance.showCountdown;
        var maintBrandEl = document.getElementById('maintenanceBrandAsset');
        if (maintBrandEl) maintBrandEl.value = maintenance.brandAsset || 'none';
        var maintImageLayoutEl = document.getElementById('maintenanceImageLayout');
        if (maintImageLayoutEl) maintImageLayoutEl.value = normalizeVisualImageLayout(maintenance.imageLayout || 'none');
        var maintOverlayColorEl = document.getElementById('maintenanceOverlayColor');
        if (maintOverlayColorEl) maintOverlayColorEl.value = maintenance.overlayColor || '#ffffff';
        setOverlayOpacity('maintenanceOverlayOpacity', 'maintenanceOverlayOpacityValue', maintenance.overlayOpacity, 88);
        updateVisualOverlayVisibility('maintenanceImageLayout', 'maintenanceOverlayColorGroup');
        var maintImageEl = document.getElementById('maintenanceImage');
        if (maintImageEl) {
            maintImageEl.value = maintenance.image || '';
            updateClearButton(maintImageEl);
        }
        var bypassParamEl = document.getElementById('maintenanceBypassParam');
        if (bypassParamEl) bypassParamEl.value = maintenance.bypassParam || 'preview';
        var bypassKeyEl = document.getElementById('maintenanceBypassKey');
        if (bypassKeyEl) bypassKeyEl.value = '';
        var bypassHintEl = document.getElementById('maintenanceBypassHint');
        if (bypassHintEl) bypassHintEl.textContent = maintenance.hasBypassKey ? t('settings.access_bypass_key_set') : t('settings.access_bypass_key_hint');
        var modules = settings.modules || {};
        var moduleAiEl = document.getElementById('moduleAi');
        if (moduleAiEl) moduleAiEl.checked = modules.ai !== false;
        var moduleNewsEl = document.getElementById('moduleNews');
        if (moduleNewsEl) moduleNewsEl.checked = modules.news !== false;
        var moduleEventsEl = document.getElementById('moduleEvents');
        if (moduleEventsEl) moduleEventsEl.checked = modules.events !== false;
        var moduleMessagesEl = document.getElementById('moduleMessages');
        if (moduleMessagesEl) moduleMessagesEl.checked = modules.messages !== false;
        var moduleIconManagerEl = document.getElementById('moduleIconManager');
        if (moduleIconManagerEl) moduleIconManagerEl.checked = modules.iconManager !== false;
        const analyticsEnabled = document.getElementById('analyticsEnabled');
        if (analyticsEnabled) analyticsEnabled.checked = settings.privacy?.analyticsEnabled !== false;
        var obfuscationEl = document.getElementById('emailObfuscation');
        if (obfuscationEl) obfuscationEl.checked = !!(settings.privacy && settings.privacy.emailObfuscation);
        var rememberThemeEl = document.getElementById('rememberPublicTheme');
        if (rememberThemeEl) rememberThemeEl.checked = !(settings.privacy && settings.privacy.rememberPublicTheme === false);

        updateColorPreview(primary, accent);
        updateBtnStylePreview();

        // Email
        var email = settings.email || {};
        var methodSelect = document.getElementById('settingsEmailMethod');
        if (methodSelect) methodSelect.value = email.method || 'inactive';
        document.getElementById('settingsRecipientEmail').value = email.recipientEmail || '';
        document.getElementById('settingsBccEmail').value = email.bccEmail || '';
        document.getElementById('settingsFromEmail').value = email.fromEmail || '';
        document.getElementById('settingsFromName').value = email.fromName || '';
        document.getElementById('settingsSmtpHost').value = email.smtpHost || '';
        document.getElementById('settingsSmtpPort').value = email.smtpPort || 587;
        document.getElementById('settingsSmtpUsername').value = email.smtpUsername || '';
        document.getElementById('settingsSmtpPassword').value = '';
        document.getElementById('settingsSmtpEncryption').value = email.smtpEncryption || 'tls';
        // Show/hide SMTP fields based on method
        toggleSmtpFields(email.method || 'inactive');
        // Mark password field if saved
        if (email.smtpPassword) {
            document.getElementById('settingsSmtpPassword').placeholder = '••••••••';
        }
    }

    function updateLogoPreview(path) {
        var img = document.getElementById('logoPreviewImg');
        if (path) {
            img.src = path;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
        updateLogoDisplayVisibility();
    }

    function updateFaviconPreview(path) {
        var img = document.getElementById('faviconPreviewImg');
        if (path) {
            img.src = path;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
    }

    function updateFaviconPngPreview(path) {
        var img = document.getElementById('faviconPngPreviewImg');
        if (!img) return;
        if (path) {
            img.src = path;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
    }

    function updateAdminLogoPreview(path) {
        var img = document.getElementById('adminLogoPreviewImg');
        if (!img) return;
        if (path) {
            img.src = path;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
    }

    function updateLogoDarkPreview(path) {
        var img = document.getElementById('logoDarkPreviewImg');
        if (!img) return;
        if (path) {
            img.src = path;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
    }

    function updateDefaultOgPreview(path) {
        var img = document.getElementById('defaultOgPreviewImg');
        if (!img) return;
        if (path) {
            img.src = path;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
    }

    // 3-way logo display selector is only relevant when no logo is set
    function updateLogoDisplayVisibility() {
        var logoVal = document.getElementById('settingsLogo').value.trim();
        var group = document.getElementById('logoDisplayGroup');
        if (group) group.style.display = logoVal ? 'none' : '';
    }

    function normalizeVisualImageLayout(layout) {
        return layout === 'split' ? 'left' : (['none', 'background', 'left', 'right'].includes(layout) ? layout : 'none');
    }

    function updateVisualOverlayVisibility(layoutSelectId, groupId) {
        var select = document.getElementById(layoutSelectId);
        var group = document.getElementById(groupId);
        if (!select || !group) return;
        group.hidden = normalizeVisualImageLayout(select.value) !== 'background';
    }

    function setOverlayOpacity(inputId, valueId, value, fallback) {
        var input = document.getElementById(inputId);
        var valueEl = document.getElementById(valueId);
        if (!input) return;
        var numeric = Number.isFinite(Number(value)) ? Number(value) : fallback;
        numeric = Math.max(0, Math.min(100, Math.round(numeric)));
        input.value = numeric;
        if (valueEl) valueEl.textContent = numeric + '%';
    }

    function syncOverlayOpacity(inputId, valueId) {
        var input = document.getElementById(inputId);
        if (!input) return;
        setOverlayOpacity(inputId, valueId, input.value, Number(input.value) || 0);
    }

    function updateLoginBoxColorVisibility() {
        var select = document.getElementById('loginBoxStyle');
        var group = document.getElementById('loginBoxColorGroup');
        if (!select || !group) return;
        group.hidden = select.value !== 'card';
    }

    // ============================================================
    // THEME COLORS — auto-derivation, auto-badge, live preview
    // ============================================================

    // Defaults — kept in sync with server-side ($defaults in api.php load-settings)
    var THEME_DEFAULTS = {
        adminTheme: 'light',
        primaryColor: '#3858e9',
        accentColor: '#3858e9',
        sidebarBg: '',
        darkPrimaryColor: '',
        darkAccentColor: '',
        darkSidebarBg: '',
        buttonGlow: true,
        buttonRadius: 6
    };

    var BRANDING_DEFAULTS = {
        favicon: <?php echo json_encode($_defaultFavicon); ?>,
        favicon_png: '',
        logo: '',
        logoDark: '',
        adminLogo: '',
        name: <?php echo json_encode(defined('SITE_NAME') ? SITE_NAME : 'CMS'); ?>,
        showBranding: true,
        logoDisplay: 'both',
        logoSize: 'medium',
        defaultOgImage: ''
    };

    // Sidebar bg derivations — match the CSS color-mix() on first paint
    function deriveSidebarLight(primary) {
        return mixColors(primary, '#ffffff', 0.12);
    }
    function deriveSidebarDark(primary) {
        return mixColors(primary, '#0b0d12', 0.10);
    }

    // Mix two hex colors (sRGB approximation of CSS color-mix(in srgb, a X%, b))
    function mixColors(a, b, ratio) {
        a = a.replace('#', '');
        b = b.replace('#', '');
        var ar = parseInt(a.substring(0, 2), 16);
        var ag = parseInt(a.substring(2, 4), 16);
        var ab = parseInt(a.substring(4, 6), 16);
        var br = parseInt(b.substring(0, 2), 16);
        var bg = parseInt(b.substring(2, 4), 16);
        var bb = parseInt(b.substring(4, 6), 16);
        var r = Math.round(ar * ratio + br * (1 - ratio));
        var g = Math.round(ag * ratio + bg * (1 - ratio));
        var bl = Math.round(ab * ratio + bb * (1 - ratio));
        return '#' + [r, g, bl].map(function(c) { return c.toString(16).padStart(2, '0'); }).join('');
    }

    function normalizeHexColor(value) {
        return String(value || '').trim().toLowerCase();
    }

    function hexToRgb(hex) {
        hex = normalizeHexColor(hex).replace('#', '');
        return [
            parseInt(hex.substring(0, 2), 16),
            parseInt(hex.substring(2, 4), 16),
            parseInt(hex.substring(4, 6), 16)
        ];
    }

    function relativeLuminance(hex) {
        return hexToRgb(hex).map(function(channel) {
            var value = channel / 255;
            return value <= 0.03928
                ? value / 12.92
                : Math.pow((value + 0.055) / 1.055, 2.4);
        }).reduce(function(total, channel, index) {
            return total + channel * [0.2126, 0.7152, 0.0722][index];
        }, 0);
    }

    function contrastRatio(a, b) {
        var l1 = relativeLuminance(a);
        var l2 = relativeLuminance(b);
        var lighter = Math.max(l1, l2);
        var darker = Math.min(l1, l2);
        return (lighter + 0.05) / (darker + 0.05);
    }

    function adjustColorForContrast(hex, background, minimumRatio, direction) {
        hex = normalizeHexColor(hex);
        if (contrastRatio(hex, background) >= minimumRatio) return hex;
        var target = direction === 'lighter' ? '#ffffff' : '#000000';
        for (var step = 1; step <= 20; step++) {
            var candidate = mixColors(hex, target, 1 - (step * 0.05));
            if (contrastRatio(candidate, background) >= minimumRatio) {
                return candidate;
            }
        }
        return target;
    }

    function sanitizeThemeContrast(theme) {
        var result = Object.assign({}, theme);
        var warnings = [];
        var minReadable = 3.0;

        function enforce(key, background, direction, labelKey) {
            if (!result[key]) return;
            var original = normalizeHexColor(result[key]);
            var adjusted = adjustColorForContrast(original, background, minReadable, direction);
            result[key] = adjusted;
            if (adjusted !== original) {
                warnings.push(t('settings.theme_color_adjusted', {field: t(labelKey), value: adjusted}));
            }
        }

        enforce('primaryColor', '#ffffff', 'darker', 'settings.primary_color');
        enforce('accentColor', '#ffffff', 'darker', 'settings.accent_color');
        enforce('darkPrimaryColor', '#0b0d12', 'lighter', 'settings.primary_color');
        enforce('darkAccentColor', '#0b0d12', 'lighter', 'settings.accent_color');

        if (!result.darkPrimaryColor && result.primaryColor && contrastRatio(result.primaryColor, '#0b0d12') < minReadable) {
            result.darkPrimaryColor = adjustColorForContrast(result.primaryColor, '#0b0d12', minReadable, 'lighter');
            warnings.push(t('settings.theme_color_adjusted', {field: t('settings.primary_color'), value: result.darkPrimaryColor}));
        }

        if (!result.darkAccentColor && result.accentColor && contrastRatio(result.accentColor, '#0b0d12') < minReadable) {
            result.darkAccentColor = adjustColorForContrast(result.accentColor, '#0b0d12', minReadable, 'lighter');
            warnings.push(t('settings.theme_color_adjusted', {field: t('settings.accent_color'), value: result.darkAccentColor}));
        }

        enforce('sidebarBg', '#1a1a1a', 'lighter', 'settings.sidebar_bg');
        enforce('darkSidebarBg', '#e5e5e5', 'darker', 'settings.sidebar_bg');

        return { theme: result, warnings: warnings };
    }

    function updateThemeContrastFeedback() {
        var minReadable = 3.0;
        var values = {
            primaryColor: document.getElementById('settingsPrimaryColor').value,
            accentColor: document.getElementById('settingsAccentColor').value,
            sidebarBg: document.getElementById('settingsSidebarBg').value,
            darkPrimaryColor: document.getElementById('settingsDarkPrimaryColor').value,
            darkAccentColor: document.getElementById('settingsDarkAccentColor').value,
            darkSidebarBg: document.getElementById('settingsDarkSidebarBg').value
        };
        var checks = {
            primaryColor: {background: '#ffffff', textContrast: false},
            accentColor: {background: '#ffffff', textContrast: false},
            sidebarBg: {background: '#1a1a1a', textContrast: true},
            darkPrimaryColor: {background: '#0b0d12', textContrast: false},
            darkAccentColor: {background: '#0b0d12', textContrast: false},
            darkSidebarBg: {background: '#e5e5e5', textContrast: true}
        };
        var hex = /^#[0-9a-fA-F]{6}$/;

        Object.keys(checks).forEach(function(key) {
            var el = document.querySelector('.theme-contrast-feedback[data-contrast-for="' + key + '"]');
            if (!el) return;
            var value = values[key];
            if (!hex.test(value)) {
                el.dataset.state = 'error';
                el.textContent = t('settings.contrast_invalid');
                return;
            }
            var ratio = contrastRatio(value, checks[key].background);
            var state = ratio < minReadable ? 'error' : 'ok';
            var keyLabel = checks[key].textContrast
                ? (ratio < minReadable ? 'settings.text_contrast_error' : 'settings.text_contrast_ok')
                : (ratio < minReadable ? 'settings.contrast_error' : 'settings.contrast_ok');
            el.dataset.state = state;
            el.textContent = t(keyLabel, {
                ratio: ratio.toFixed(1)
            });
        });
    }

    // Track which optional fields are in "auto" mode (empty in JSON, derived for display)
    var AUTO_STATE = {
        sidebarBg: true,
        darkPrimaryColor: true,
        darkAccentColor: true,
        darkSidebarBg: true
    };

    // Set an optional field's value: empty stored value = auto (show derived, badge on)
    function setAutoField(name, storedValue, derivedValue) {
        var hex = document.getElementById('settings' + capitalize(name));
        var picker = document.getElementById('settings' + capitalize(name) + 'Picker');
        var badge = document.querySelector('.auto-badge[data-auto-for="' + name + '"]');
        var isAuto = !storedValue;
        AUTO_STATE[name] = isAuto;
        var displayValue = isAuto ? derivedValue : storedValue;
        if (hex) hex.value = displayValue;
        if (picker) picker.value = displayValue;
        if (badge) badge.hidden = !isAuto;
    }

    function capitalize(s) {
        return s.charAt(0).toUpperCase() + s.slice(1);
    }

    // Read current theme state from the form (returns the same shape as settings.theme)
    function readThemeFormState() {
        return {
            adminTheme: document.getElementById('settingsAdminTheme').value,
            primaryColor: normalizeHexColor(document.getElementById('settingsPrimaryColor').value),
            accentColor: normalizeHexColor(document.getElementById('settingsAccentColor').value),
            sidebarBg: AUTO_STATE.sidebarBg ? '' : normalizeHexColor(document.getElementById('settingsSidebarBg').value),
            darkPrimaryColor: AUTO_STATE.darkPrimaryColor ? '' : normalizeHexColor(document.getElementById('settingsDarkPrimaryColor').value),
            darkAccentColor: AUTO_STATE.darkAccentColor ? '' : normalizeHexColor(document.getElementById('settingsDarkAccentColor').value),
            darkSidebarBg: AUTO_STATE.darkSidebarBg ? '' : normalizeHexColor(document.getElementById('settingsDarkSidebarBg').value),
            buttonGlow: document.getElementById('settingsButtonGlow').checked,
            buttonRadius: parseInt(document.getElementById('settingsButtonRadius').value, 10)
        };
    }

    function syncThemeFormColors(theme) {
        function setPair(id, value) {
            var input = document.getElementById(id);
            var picker = document.getElementById(id + 'Picker');
            if (input && value) input.value = value;
            if (picker && value) picker.value = value;
        }

        setPair('settingsPrimaryColor', theme.primaryColor);
        setPair('settingsAccentColor', theme.accentColor);
        if (!AUTO_STATE.sidebarBg) setPair('settingsSidebarBg', theme.sidebarBg);
        if (!AUTO_STATE.darkPrimaryColor) setPair('settingsDarkPrimaryColor', theme.darkPrimaryColor);
        if (!AUTO_STATE.darkAccentColor) setPair('settingsDarkAccentColor', theme.darkAccentColor);
        if (!AUTO_STATE.darkSidebarBg) setPair('settingsDarkSidebarBg', theme.darkSidebarBg);
        refreshAutoDisplays();
    }

    // Re-derive auto-fields when their source colors change (cascading display)
    function refreshAutoDisplays() {
        var primary = document.getElementById('settingsPrimaryColor').value;
        var accent = document.getElementById('settingsAccentColor').value;
        var hex = /^#[0-9a-fA-F]{6}$/;

        if (AUTO_STATE.sidebarBg && hex.test(primary)) {
            var v = deriveSidebarLight(primary);
            document.getElementById('settingsSidebarBg').value = v;
            document.getElementById('settingsSidebarBgPicker').value = v;
        }
        if (AUTO_STATE.darkPrimaryColor && hex.test(primary)) {
            document.getElementById('settingsDarkPrimaryColor').value = primary;
            document.getElementById('settingsDarkPrimaryColorPicker').value = primary;
        }
        if (AUTO_STATE.darkAccentColor && hex.test(accent)) {
            document.getElementById('settingsDarkAccentColor').value = accent;
            document.getElementById('settingsDarkAccentColorPicker').value = accent;
        }
        // Dark sidebar derives from resolved dark primary (which may itself be auto)
        var darkPrimary = AUTO_STATE.darkPrimaryColor
            ? primary
            : document.getElementById('settingsDarkPrimaryColor').value;
        if (AUTO_STATE.darkSidebarBg && hex.test(darkPrimary)) {
            var sd = deriveSidebarDark(darkPrimary);
            document.getElementById('settingsDarkSidebarBg').value = sd;
            document.getElementById('settingsDarkSidebarBgPicker').value = sd;
        }
    }

    // Live-update CSS variables on the <html> element (for both themes)
    function updateColorPreview() {
        refreshAutoDisplays();
        var theme = readThemeFormState();
        applyTheme(theme);
        updateThemeContrastFeedback();
    }

    // Combined button preview — uses primary color of the currently active theme
    function updateBtnStylePreview() {
        var glow = document.getElementById('settingsButtonGlow').checked;
        var radius = parseInt(document.getElementById('settingsButtonRadius').value, 10);
        var activeTheme = document.documentElement.getAttribute('data-site-theme') || 'light';
        var primary;
        if (activeTheme === 'dark') {
            primary = AUTO_STATE.darkPrimaryColor
                ? document.getElementById('settingsPrimaryColor').value
                : document.getElementById('settingsDarkPrimaryColor').value;
        } else {
            primary = document.getElementById('settingsPrimaryColor').value;
        }
        var btnPrimary = document.getElementById('previewBtnPrimary');
        var btnSecondary = document.getElementById('previewBtnSecondary');
        if (btnPrimary) {
            var primaryGlow = adjustColor(primary, 30);
            btnPrimary.style.background = glow
                ? 'radial-gradient(ellipse at 50% 0%, ' + primaryGlow + ' 0%, ' + primary + ' 70%)'
                : primary;
            btnPrimary.style.borderRadius = radius + 'px';
            if (glow) {
                btnPrimary.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15), 0 4px 20px ' + hexToRgba(primary, 0.35);
            } else {
                btnPrimary.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15)';
            }
        }
        if (btnSecondary) {
            btnSecondary.style.borderRadius = radius + 'px';
        }
    }

    document.getElementById('settingsButtonGlow').addEventListener('change', updateBtnStylePreview);
    document.getElementById('settingsButtonRadius').addEventListener('input', function() {
        document.getElementById('settingsButtonRadiusValue').textContent = this.value + 'px';
        updateBtnStylePreview();
    });

    // Theme-aware browser favicon (recolors SVG currentColor on theme change)
    var THEME_FAVICON_COLORS = { light: '#0a0a0a', dark: '#e5e5e5' };
    var faviconSvgCache = null;
    function updateAdminBrowserFavicon(theme) {
        var link = document.querySelector('link[rel="icon"]');
        if (!link) return;
        var href = link.getAttribute('data-original-href') || link.getAttribute('href');
        if (!href || !/\.svg(\?|#|$)/i.test(href)) return;
        if (!link.getAttribute('data-original-href')) link.setAttribute('data-original-href', href);
        function apply() {
            if (!faviconSvgCache) return;
            var color = THEME_FAVICON_COLORS[theme] || THEME_FAVICON_COLORS.light;
            var patched = faviconSvgCache
                .replace(/<svg\b/, '<svg data-theme="' + theme + '"')
                .replace(/currentColor/g, color);
            link.setAttribute('href', 'data:image/svg+xml;utf8,' + encodeURIComponent(patched));
        }
        if (faviconSvgCache === null) {
            fetch(href).then(function(r){ return r.ok ? r.text() : null; }).then(function(svg){
                if (svg) { faviconSvgCache = svg; apply(); }
            }).catch(function(){});
        } else {
            apply();
        }
    }
    // Initial favicon apply
    updateAdminBrowserFavicon(document.documentElement.getAttribute('data-site-theme') || 'light');

    // Theme selector buttons — instant preview on click
    document.querySelectorAll('.theme-option').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.theme-option').forEach(function(b) { b.classList.remove('selected'); });
            this.classList.add('selected');
            var themeValue = this.dataset.theme;
            document.getElementById('settingsAdminTheme').value = themeValue;
            // Instant preview
            var resolved = themeValue;
            if (resolved === 'system') {
                resolved = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-site-theme', resolved);
            updateAdminBrowserFavicon(resolved);
            updateBtnStylePreview();
        });
    });

    // Bind a hex/picker pair. `optional` = field can be in auto mode; manual edits exit auto.
    function bindColorPair(name, optional) {
        var cap = capitalize(name);
        var hex = document.getElementById('settings' + cap);
        var picker = document.getElementById('settings' + cap + 'Picker');
        if (!hex || !picker) return;

        function onPicker() {
            hex.value = this.value;
            if (optional) {
                AUTO_STATE[name] = false;
                var badge = document.querySelector('.auto-badge[data-auto-for="' + name + '"]');
                if (badge) badge.hidden = true;
            }
            updateColorPreview();
        }
        function onHex() {
            if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
                picker.value = this.value;
                if (optional) {
                    AUTO_STATE[name] = false;
                    var badge = document.querySelector('.auto-badge[data-auto-for="' + name + '"]');
                    if (badge) badge.hidden = true;
                }
                updateColorPreview();
            }
        }
        picker.addEventListener('input', onPicker);
        picker.addEventListener('change', updateThemeContrastFeedback);
        hex.addEventListener('input', onHex);
        hex.addEventListener('blur', function() {
            if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
                this.value = normalizeHexColor(this.value);
                picker.value = this.value;
                updateColorPreview();
            } else {
                updateThemeContrastFeedback();
            }
        });
        hex.addEventListener('change', updateThemeContrastFeedback);
    }

    bindColorPair('primaryColor', false);
    bindColorPair('accentColor', false);
    bindColorPair('sidebarBg', true);
    bindColorPair('darkPrimaryColor', true);
    bindColorPair('darkAccentColor', true);
    bindColorPair('darkSidebarBg', true);

    function setLoginColorPair(id, value) {
        var normalized = /^#[0-9a-fA-F]{6}$/.test(value || '') ? normalizeHexColor(value) : '';
        var hex = document.getElementById(id);
        var picker = document.getElementById(id + 'Picker');
        if (hex && normalized) hex.value = normalized;
        if (picker && normalized) picker.value = normalized;
    }

    function bindLoginColorPair(id) {
        var hex = document.getElementById(id);
        var picker = document.getElementById(id + 'Picker');
        if (!hex || !picker) return;

        picker.addEventListener('input', function() {
            hex.value = this.value;
        });
        hex.addEventListener('input', function() {
            if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
                picker.value = this.value;
            }
        });
        hex.addEventListener('blur', function() {
            if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
                this.value = normalizeHexColor(this.value);
                picker.value = this.value;
            }
        });
    }

    bindLoginColorPair('loginBoxColor');
    bindLoginColorPair('loginBoxTextColor');

    // Auto-reset buttons — return a field to "auto" (empty stored, derived display)
    document.querySelectorAll('.auto-reset-btn[data-auto-reset]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var name = btn.dataset.autoReset;
            AUTO_STATE[name] = true;
            var badge = document.querySelector('.auto-badge[data-auto-for="' + name + '"]');
            if (badge) badge.hidden = false;
            updateColorPreview();
        });
    });

    // Reset all colors button — restores defaults (without saving)
    document.getElementById('resetThemeBtn').addEventListener('click', function() {
        document.getElementById('settingsPrimaryColor').value = THEME_DEFAULTS.primaryColor;
        document.getElementById('settingsPrimaryColorPicker').value = THEME_DEFAULTS.primaryColor;
        document.getElementById('settingsAccentColor').value = THEME_DEFAULTS.accentColor;
        document.getElementById('settingsAccentColorPicker').value = THEME_DEFAULTS.accentColor;
        // All optional fields back to auto
        setAutoField('sidebarBg', '', deriveSidebarLight(THEME_DEFAULTS.primaryColor));
        setAutoField('darkPrimaryColor', '', THEME_DEFAULTS.primaryColor);
        setAutoField('darkAccentColor', '', THEME_DEFAULTS.accentColor);
        setAutoField('darkSidebarBg', '', deriveSidebarDark(THEME_DEFAULTS.primaryColor));
        updateColorPreview();
    });

    // Browse logo button — opens the image manager
    document.getElementById('browseLogoBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsLogo');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    // Browse dark-logo button — opens the image manager
    document.getElementById('browseLogoDarkBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsLogoDark');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    // Browse admin-logo button — opens the image manager
    document.getElementById('browseAdminLogoBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsAdminLogo');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    // Browse favicon button — opens the image manager
    document.getElementById('browseFaviconBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsFavicon');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    // Browse PNG favicon button — opens the image manager
    document.getElementById('browseFaviconPngBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsFaviconPng');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    document.getElementById('browseDefaultOgBtn').addEventListener('click', function() {
        var input = document.getElementById('settingsDefaultOgImage');
        NbImageManager.open(function(path) {
            setOgImageInputValue(input, path);
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    var browseLoginImageBtn = document.getElementById('browseLoginImageBtn');
    if (browseLoginImageBtn) browseLoginImageBtn.addEventListener('click', function() {
        var input = document.getElementById('loginImage');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    var browseMaintenanceImageBtn = document.getElementById('browseMaintenanceImageBtn');
    if (browseMaintenanceImageBtn) browseMaintenanceImageBtn.addEventListener('click', function() {
        var input = document.getElementById('maintenanceImage');
        NbImageManager.open(function(path) {
            input.value = path;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, input ? input.value : null, { types: ['image'], type: 'image' });
    });

    // Manual edits to the logo path also toggle the 3-way selector
    document.getElementById('settingsLogo').addEventListener('input', function() {
        updateLogoPreview(this.value.trim());
        updateClearButton(this);
    });
    document.getElementById('settingsLogoDark').addEventListener('input', function() {
        updateLogoDarkPreview(this.value.trim());
        updateClearButton(this);
    });
    document.getElementById('settingsAdminLogo').addEventListener('input', function() {
        updateAdminLogoPreview(this.value.trim());
        updateClearButton(this);
    });
    document.getElementById('settingsFavicon').addEventListener('input', function() {
        updateFaviconPreview(this.value.trim());
        updateClearButton(this);
    });
    document.getElementById('settingsFaviconPng').addEventListener('input', function() {
        updateFaviconPngPreview(this.value.trim());
        updateClearButton(this);
    });
    document.getElementById('settingsDefaultOgImage').addEventListener('input', function() {
        updateDefaultOgPreview(this.value.trim());
        updateClearButton(this);
    });
    var loginImageInput = document.getElementById('loginImage');
    if (loginImageInput) loginImageInput.addEventListener('input', function() {
        updateClearButton(this);
    });
    var loginImageLayoutSelect = document.getElementById('loginImageLayout');
    if (loginImageLayoutSelect) loginImageLayoutSelect.addEventListener('change', function() {
        updateVisualOverlayVisibility('loginImageLayout', 'loginOverlayColorGroup');
    });
    var loginOverlayOpacityInput = document.getElementById('loginOverlayOpacity');
    if (loginOverlayOpacityInput) loginOverlayOpacityInput.addEventListener('input', function() {
        syncOverlayOpacity('loginOverlayOpacity', 'loginOverlayOpacityValue');
    });
    var loginBoxStyleSelect = document.getElementById('loginBoxStyle');
    if (loginBoxStyleSelect) loginBoxStyleSelect.addEventListener('change', updateLoginBoxColorVisibility);
    var maintenanceImageInput = document.getElementById('maintenanceImage');
    if (maintenanceImageInput) maintenanceImageInput.addEventListener('input', function() {
        updateClearButton(this);
    });
    var maintenanceImageLayoutSelect = document.getElementById('maintenanceImageLayout');
    if (maintenanceImageLayoutSelect) maintenanceImageLayoutSelect.addEventListener('change', function() {
        updateVisualOverlayVisibility('maintenanceImageLayout', 'maintenanceOverlayColorGroup');
    });
    var maintenanceOverlayOpacityInput = document.getElementById('maintenanceOverlayOpacity');
    if (maintenanceOverlayOpacityInput) maintenanceOverlayOpacityInput.addEventListener('input', function() {
        syncOverlayOpacity('maintenanceOverlayOpacity', 'maintenanceOverlayOpacityValue');
    });

    document.getElementById('resetBrandingBtn').addEventListener('click', function() {
        document.getElementById('settingsFavicon').value = BRANDING_DEFAULTS.favicon;
        document.getElementById('settingsFaviconPng').value = BRANDING_DEFAULTS.favicon_png;
        document.getElementById('settingsLogo').value = BRANDING_DEFAULTS.logo;
        document.getElementById('settingsLogoDark').value = BRANDING_DEFAULTS.logoDark;
        document.getElementById('settingsAdminLogo').value = BRANDING_DEFAULTS.adminLogo;
        document.getElementById('settingsDefaultOgImage').value = BRANDING_DEFAULTS.defaultOgImage;
        document.getElementById('settingsName').value = BRANDING_DEFAULTS.name;
        document.getElementById('settingsShowBranding').checked = BRANDING_DEFAULTS.showBranding;
        var displayRadio = document.querySelector('input[name="settingsLogoDisplay"][value="' + BRANDING_DEFAULTS.logoDisplay + '"]');
        if (displayRadio) displayRadio.checked = true;
        var sizeRadio = document.querySelector('input[name="settingsLogoSize"][value="' + BRANDING_DEFAULTS.logoSize + '"]');
        if (sizeRadio) sizeRadio.checked = true;
        updateFaviconPreview(BRANDING_DEFAULTS.favicon);
        updateFaviconPngPreview(BRANDING_DEFAULTS.favicon_png);
        updateLogoPreview(BRANDING_DEFAULTS.logo);
        updateLogoDarkPreview(BRANDING_DEFAULTS.logoDark);
        updateAdminLogoPreview(BRANDING_DEFAULTS.adminLogo);
        updateDefaultOgPreview(BRANDING_DEFAULTS.defaultOgImage);
        document.querySelectorAll('.input-clear-btn[data-clear-target]').forEach(function(btn) {
            var input = document.getElementById(btn.dataset.clearTarget);
            if (input) updateClearButton(input);
        });
    });

    // Generic clear-X handler for image-path inputs
    function updateClearButton(input) {
        var btn = document.querySelector('.input-clear-btn[data-clear-target="' + input.id + '"]');
        if (btn) btn.hidden = !input.value.trim();
    }
    document.querySelectorAll('.input-clear-btn[data-clear-target]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var input = document.getElementById(btn.dataset.clearTarget);
            if (!input) return;
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
        });
        // Initialize visibility
        var input = document.getElementById(btn.dataset.clearTarget);
        if (input) btn.hidden = !input.value.trim();
    });

    // Save branding
    document.getElementById('brandingForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        var btn = document.getElementById('saveBrandingBtn');
        btn.disabled = true;
        btn.textContent = t('btn.saving');

        try {
            var settings = Object.assign({}, currentSettings || {});
            settings.favicon = document.getElementById('settingsFavicon').value.trim();
            settings.favicon_png = document.getElementById('settingsFaviconPng').value.trim();
            var displayRadio = document.querySelector('input[name="settingsLogoDisplay"]:checked');
            var sizeRadio = document.querySelector('input[name="settingsLogoSize"]:checked');
            settings.branding = {
                logo: document.getElementById('settingsLogo').value.trim(),
                logoDark: document.getElementById('settingsLogoDark').value.trim(),
                adminLogo: document.getElementById('settingsAdminLogo').value.trim(),
                name: document.getElementById('settingsName').value.trim(),
                showBranding: document.getElementById('settingsShowBranding').checked,
                logoDisplay: displayRadio ? displayRadio.value : 'both',
                logoSize: sizeRadio ? sizeRadio.value : 'medium'
            };
            settings.seo = Object.assign({}, settings.seo || {}, {
                defaultOgImage: document.getElementById('settingsDefaultOgImage').value.trim()
            });

            var formData = new FormData();
            formData.append('action', 'save-settings');
            formData.append('settings', JSON.stringify(settings));
            formData.append('csrf_token', CSRF_TOKEN);

            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();

            if (result.success) {
                currentSettings = result.data;
                showToast(t('toast.branding_saved'), 'success');
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = t('settings.save_branding');
        }
    });

    // Save theme
    document.getElementById('themeForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        var btn = document.getElementById('saveThemeBtn');
        btn.disabled = true;
        btn.textContent = t('btn.saving');

        var primaryColor = document.getElementById('settingsPrimaryColor').value;
        var accentColor = document.getElementById('settingsAccentColor').value;

        if (!/^#[0-9a-fA-F]{6}$/.test(primaryColor) || !/^#[0-9a-fA-F]{6}$/.test(accentColor)) {
            showToast(t('settings.invalid_color'), 'error');
            btn.disabled = false;
            btn.textContent = t('settings.save_theme');
            return;
        }

        try {
            var settings = Object.assign({}, currentSettings || {});
            var contrastResult = sanitizeThemeContrast(readThemeFormState());
            settings.theme = contrastResult.theme;
            settings.dashboard = Object.assign({}, settings.dashboard || {}, {
                itemsPerPage: clampDashboardPageSize(document.getElementById('settingsItemsPerPage')?.value),
                iconManagerItemsPerPage: clampDashboardPageSize(document.getElementById('settingsIconItemsPerPage')?.value),
                mediaItemsPerPage: clampMediaPageSize(document.getElementById('settingsMediaItemsPerPage')?.value)
            });
            syncThemeFormColors(settings.theme);
            updateThemeContrastFeedback();

            var formData = new FormData();
            formData.append('action', 'save-settings');
            formData.append('settings', JSON.stringify(settings));
            formData.append('csrf_token', CSRF_TOKEN);

            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();

            if (result.success) {
                currentSettings = result.data;
                applyTheme(currentSettings.theme);
                if (window.NbImageManager) {
                    NbImageManager.init({
                        itemsPerPage: clampMediaPageSize(currentSettings?.dashboard?.mediaItemsPerPage)
                    });
                    if (typeof NbImageManager.refresh === 'function') {
                        NbImageManager.refresh();
                    }
                }
                var serverTheme = sanitizeThemeContrast(currentSettings.theme || {});
                if (serverTheme.warnings.length) {
                    syncThemeFormColors(serverTheme.theme);
                    updateThemeContrastFeedback();
                    showToast(serverTheme.warnings[0], 'warning');
                } else if (contrastResult.warnings.length) {
                    updateThemeContrastFeedback();
                    showToast(contrastResult.warnings[0], 'warning');
                } else {
                    updateThemeContrastFeedback();
                    showToast(t('toast.theme_saved'), 'success');
                }
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = t('settings.save_theme');
        }
    });

    // Resolve theme into light + dark color sets (dark falls back to light when empty)
    function resolveThemeColors(theme) {
        var light = {
            primary: theme.primaryColor || THEME_DEFAULTS.primaryColor,
            accent: theme.accentColor || THEME_DEFAULTS.accentColor,
            sidebar: theme.sidebarBg || ''
        };
        var dark = {
            primary: theme.darkPrimaryColor || light.primary,
            accent: theme.darkAccentColor || light.accent,
            sidebar: theme.darkSidebarBg || ''
        };
        if (!light.sidebar) light.sidebar = deriveSidebarLight(light.primary);
        if (!dark.sidebar) dark.sidebar = deriveSidebarDark(dark.primary);
        return { light: light, dark: dark };
    }

    // Inject a per-theme stylesheet; replaces the one we ship server-side on save/preview
    function injectThemeStyles(colors, glow) {
        var existing = document.getElementById('nb-theme-runtime');
        if (existing) existing.remove();
        var style = document.createElement('style');
        style.id = 'nb-theme-runtime';

        function block(selector, c) {
            var pcLight = adjustColor(c.primary, 30);
            var btnGradient = glow === false
                ? c.primary
                : 'radial-gradient(ellipse at 50% 0%, ' + pcLight + ' 0%, ' + c.primary + ' 70%)';
            var btnHover = glow === false
                ? adjustColor(c.primary, -15)
                : 'radial-gradient(ellipse at 50% 0%, ' + adjustColor(pcLight, 20) + ' 0%, ' + c.primary + ' 70%)';
            // Subtle/muted/medium tints derived from primary so hover/active
            // states pick up the user's branding instead of the static blue
            // defaults in nibbly-admin-tokens.css.
            var isDark = selector.indexOf('dark') !== -1;
            var subtleAlpha = isDark ? 0.12 : 0.08;
            var mutedAlpha = isDark ? 0.22 : 0.15;
            var mediumAlpha = isDark ? 0.38 : 0.30;
            var bg = isDark ? adjustColor(c.sidebar, 8) : adjustColor(c.sidebar, 8);
            var bgElevated = isDark ? adjustColor(c.sidebar, 18) : adjustColor(c.sidebar, 18);
            var bgSunken = isDark ? adjustColor(c.sidebar, -2) : adjustColor(c.sidebar, -6);
            var bgHover = isDark ? adjustColor(c.sidebar, 28) : adjustColor(c.sidebar, 4);
            var border = isDark ? adjustColor(c.sidebar, 42) : adjustColor(c.sidebar, -20);
            var borderStrong = isDark ? adjustColor(c.sidebar, 58) : adjustColor(c.sidebar, -34);
            return selector + ' {' +
                '--nb-primary: ' + c.primary + ';' +
                '--nb-primary-hover: ' + adjustColor(c.primary, -15) + ';' +
                '--nb-primary-active: ' + adjustColor(c.primary, -25) + ';' +
                '--nb-primary-subtle: ' + hexToRgba(c.primary, subtleAlpha) + ';' +
                '--nb-primary-muted: ' + hexToRgba(c.primary, mutedAlpha) + ';' +
                '--nb-primary-medium: ' + hexToRgba(c.primary, mediumAlpha) + ';' +
                '--nb-primary-btn: ' + btnGradient + ';' +
                '--nb-primary-btn-hover: ' + btnHover + ';' +
                '--nb-brand: ' + c.accent + ';' +
                '--nb-brand-light: ' + adjustColor(c.accent, 20) + ';' +
                '--nb-brand-dark: ' + adjustColor(c.accent, -20) + ';' +
                '--nb-brand-subtle: ' + hexToRgba(c.accent, isDark ? 0.18 : 0.12) + ';' +
                '--nb-sidebar-bg: ' + c.sidebar + ';' +
                '--nb-bg: ' + bg + ';' +
                '--nb-bg-elevated: ' + bgElevated + ';' +
                '--nb-bg-sunken: ' + bgSunken + ';' +
                '--nb-bg-hover: ' + bgHover + ';' +
                '--nb-border: ' + border + ';' +
                '--nb-border-strong: ' + borderStrong + ';' +
            '}';
        }

        style.textContent =
            block(':root, [data-site-theme="light"]', colors.light) +
            block('[data-site-theme="dark"]', colors.dark);
        document.head.appendChild(style);
    }

    // Apply theme live
    function applyTheme(theme) {
        var themeValue = theme.adminTheme || 'light';
        if (themeValue === 'system') {
            themeValue = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-site-theme', themeValue);
        localStorage.setItem('site-admin-theme', theme.adminTheme);

        var colors = resolveThemeColors(theme);
        injectThemeStyles(colors, theme.buttonGlow);

        // Button radius — affects both admin and frontend editor buttons
        if (theme.buttonRadius != null) {
            document.documentElement.style.setProperty('--editor-btn-radius', theme.buttonRadius + 'px');
        }

        // Flat button classes (glow disabled)
        document.documentElement.classList.toggle('editor-flat', theme.buttonGlow === false);
        document.documentElement.classList.toggle('nb-flat-buttons', theme.buttonGlow === false);

        updateBtnStylePreview();
    }

    function adjustColor(hex, amount) {
        hex = hex.replace('#', '');
        var r = Math.max(0, Math.min(255, parseInt(hex.substring(0, 2), 16) + amount));
        var g = Math.max(0, Math.min(255, parseInt(hex.substring(2, 4), 16) + amount));
        var b = Math.max(0, Math.min(255, parseInt(hex.substring(4, 6), 16) + amount));
        return '#' + [r, g, b].map(function(c) { return c.toString(16).padStart(2, '0'); }).join('');
    }

    function hexToRgba(hex, alpha) {
        hex = hex.replace('#', '');
        var r = parseInt(hex.substring(0, 2), 16);
        var g = parseInt(hex.substring(2, 4), 16);
        var b = parseInt(hex.substring(4, 6), 16);
        return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
    }

    // Apply saved theme immediately on page load (server-rendered)
    applyTheme(<?php echo json_encode($siteSettings['theme'] ?? []); ?>);

    var aiSettingsForm = document.getElementById('aiSettingsForm');
    if (aiSettingsForm) {
        aiSettingsForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var btn = document.getElementById('saveAiSettingsBtn');
            btn.disabled = true;
            btn.textContent = t('btn.saving');
            try {
                var formData = new FormData();
                formData.append('action', 'save-ai-settings');
                formData.append('settings', JSON.stringify(collectAiSettingsForm()));
                formData.append('csrf_token', CSRF_TOKEN);
                var configuredTimeout = parseInt(document.getElementById('aiRequestTimeout')?.value || '300', 10);
                var imageTimeoutMs = Math.max(300000, (Number.isFinite(configuredTimeout) ? configuredTimeout : 300) * 1000 + 30000);
                var result = await fetchJsonWithTimeout('api.php', { method: 'POST', body: formData }, imageTimeoutMs);
                if (!result.success) throw new Error(result.message);
                currentAiSettings = result.data.settings;
                populateAiSettings(currentAiSettings);
                updateAiUsage(result.data.usage);
                showToast(t('ai.settings_saved'), 'success');
            } catch (error) {
                showToast(error.message, 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = t('ai.save_settings');
            }
        });
    }

    var testAiBtn = document.getElementById('testAiBtn');
    if (testAiBtn) {
        testAiBtn.addEventListener('click', async function() {
            testAiBtn.disabled = true;
            testAiBtn.textContent = t('ai.testing');
            try {
                var formData = new FormData();
                formData.append('action', 'ai-test');
                formData.append('csrf_token', CSRF_TOKEN);
                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();
                if (!result.success) throw new Error(result.message);
                showToast(t('ai.connection_ok'), 'success');
                updateAiUsage(result.data.limits);
            } catch (error) {
                showToast(error.message, 'error');
            } finally {
                testAiBtn.disabled = false;
                testAiBtn.textContent = t('ai.test_connection');
            }
        });
    }

    var aiChatForm = document.getElementById('aiChatForm');
    if (aiChatForm) {
        var aiChatShortcutHint = document.querySelector('.ai-chat-shortcut-hint');
        if (aiChatShortcutHint) {
            var nav = window.navigator || {};
            var platform = (nav.userAgentData && nav.userAgentData.platform ? nav.userAgentData.platform : nav.platform || '').toLowerCase();
            var isMac = platform.indexOf('mac') !== -1 || platform.indexOf('iphone') !== -1 || platform.indexOf('ipad') !== -1;
            aiChatShortcutHint.textContent = isMac ? aiChatShortcutHint.dataset.macText : aiChatShortcutHint.dataset.otherText;
        }
        var aiChatPrompt = document.getElementById('aiChatPrompt');
        if (aiChatPrompt) {
            aiChatPrompt.addEventListener('keydown', function(e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                if (e.metaKey || e.ctrlKey) {
                    var start = aiChatPrompt.selectionStart || 0;
                    var end = aiChatPrompt.selectionEnd || 0;
                    var value = aiChatPrompt.value;
                    aiChatPrompt.value = value.slice(0, start) + '\n' + value.slice(end);
                    aiChatPrompt.selectionStart = aiChatPrompt.selectionEnd = start + 1;
                    return;
                }
                if (aiChatPrompt.value.trim()) {
                    aiChatForm.requestSubmit();
                }
            });
        }
        aiChatForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var input = document.getElementById('aiChatPrompt');
            var prompt = input.value.trim();
            if (!prompt) return;
            input.value = '';
            aiChatMessages.push({ role: 'user', content: prompt });
            appendAiChat('user', prompt);
            var btn = aiChatForm.querySelector('button[type="submit"]');
            var indicator = document.getElementById('aiChatIndicator');
            btn.disabled = true;
            if (indicator) indicator.hidden = false;
            try {
                var formData = new FormData();
                formData.append('action', 'ai-chat');
                formData.append('messages', JSON.stringify(aiChatMessages.slice(-10)));
                formData.append('csrf_token', CSRF_TOKEN);
                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();
                if (!result.success) throw new Error(result.message);
                aiChatMessages.push({ role: 'assistant', content: result.data.text });
                appendAiChat('assistant', result.data.text);
                updateAiUsage(result.data.limits);
            } catch (error) {
                appendAiChat('error', error.message);
            } finally {
                btn.disabled = false;
                if (indicator) indicator.hidden = true;
            }
        });
    }

    var aiTextForm = document.getElementById('aiTextForm');
    if (aiTextForm) {
        aiTextForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var resultBox = document.getElementById('aiTextResult');
            var prompt = document.getElementById('aiTextPrompt').value.trim();
            if (!prompt) return;
            var btn = aiTextForm.querySelector('button[type="submit"]');
            btn.disabled = true;
            resultBox.value = t('ai.generating');
            try {
                var formData = new FormData();
                formData.append('action', 'ai-generate-text');
                formData.append('prompt', prompt);
                formData.append('maxOutputTokens', document.getElementById('aiTextMaxTokens').value);
                formData.append('csrf_token', CSRF_TOKEN);
                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();
                if (!result.success) throw new Error(result.message);
                resultBox.value = result.data.text;
                updateAiUsage(result.data.limits);
            } catch (error) {
                resultBox.value = error.message;
            } finally {
                btn.disabled = false;
            }
        });
    }

    var aiAuditRunButton = document.getElementById('aiAuditRun');
    if (aiAuditRunButton) {
        aiAuditRunButton.addEventListener('click', runAiContentAudit);
    }

    async function aiAuditPost(action, payload) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('csrf_token', CSRF_TOKEN);
        Object.keys(payload || {}).forEach(function(key) {
            formData.append(key, payload[key]);
        });
        var response = await fetch('api.php', { method: 'POST', body: formData, cache: 'no-store' });
        var result = await response.json();
        if (!result.success) throw new Error(result.message || 'Error');
        return result.data;
    }

    function aiAuditStatusBadge(row) {
        var key = 'ai.audit_status_' + row.descriptionStatus;
        var cls = row.descriptionStatus === 'ok' ? 'ai-audit-badge--ok' : 'ai-audit-badge--warn';
        return '<span class="ai-audit-badge ' + cls + '">' + escapeHtml(t(key)) + ' (' + row.descriptionLength + ')</span>';
    }

    async function runAiContentAudit() {
        var results = document.getElementById('aiAuditResults');
        if (!results) return;
        aiAuditRunButton.disabled = true;
        results.textContent = t('ai.audit_running');
        try {
            var data = await aiAuditPost('ai-content-audit', {});
            var pages = Array.isArray(data.pages) ? data.pages : [];
            if (!pages.length) {
                results.textContent = t('ai.audit_empty');
                return;
            }
            var html = '<table class="ai-audit-table"><thead><tr>'
                + '<th>' + escapeHtml(t('ai.audit_col_page')) + '</th>'
                + '<th>' + escapeHtml(t('ai.audit_col_description')) + '</th>'
                + '<th>' + escapeHtml(t('ai.audit_col_alt')) + '</th>'
                + '<th></th>'
                + '</tr></thead><tbody>';
            pages.forEach(function(row, index) {
                var needsDescription = row.descriptionStatus !== 'ok';
                html += '<tr data-audit-page="' + escapeHtml(row.contentPage) + '">'
                    + '<td><strong>' + escapeHtml(row.title) + '</strong><br><small>' + escapeHtml(row.contentPage) + '</small></td>'
                    + '<td data-audit-cell="description">' + aiAuditStatusBadge(row) + '</td>'
                    + '<td>' + (row.missingAlt > 0
                        ? '<span class="ai-audit-badge ai-audit-badge--warn">' + escapeHtml(t('ai.audit_alt_missing', { count: row.missingAlt })) + '</span>'
                        : '<span class="ai-audit-badge ai-audit-badge--ok">' + escapeHtml(t('ai.audit_status_ok')) + '</span>') + '</td>'
                    + '<td data-audit-cell="actions">' + (needsDescription
                        ? '<button type="button" class="btn btn-secondary btn-sm" data-audit-suggest="' + escapeHtml(row.contentPage) + '">' + escapeHtml(t('ai.audit_suggest')) + '</button>'
                        : '') + '</td>'
                    + '</tr>';
            });
            html += '</tbody></table>';
            results.innerHTML = html;
            results.querySelectorAll('[data-audit-suggest]').forEach(function(button) {
                button.addEventListener('click', function() {
                    aiAuditSuggestDescription(button.getAttribute('data-audit-suggest'), button);
                });
            });
        } catch (error) {
            results.textContent = error.message;
        } finally {
            aiAuditRunButton.disabled = false;
        }
    }

    async function aiAuditSuggestDescription(contentPage, button) {
        var row = document.querySelector('[data-audit-page="' + (window.CSS && CSS.escape ? CSS.escape(contentPage) : contentPage) + '"]');
        if (!row) return;
        var actionsCell = row.querySelector('[data-audit-cell="actions"]');
        button.disabled = true;
        button.textContent = t('ai.generating');
        try {
            var data = await aiAuditPost('ai-content-audit-suggest', { contentPage: contentPage });
            updateAiUsage(data.limits);
            var descriptionCell = row.querySelector('[data-audit-cell="description"]');
            if (descriptionCell) {
                descriptionCell.innerHTML = '<em>' + escapeHtml(data.description) + '</em>';
            }
            actionsCell.innerHTML = '';
            var applyButton = document.createElement('button');
            applyButton.type = 'button';
            applyButton.className = 'btn btn-primary btn-sm';
            applyButton.textContent = t('ai.audit_apply');
            applyButton.addEventListener('click', function() {
                showModal(t('ai.audit_apply'), t('ai.audit_apply_confirm', { page: contentPage }), async function() {
                    closeModal();
                applyButton.disabled = true;
                try {
                    await aiAuditPost('ai-content-audit-apply', {
                        contentPage: contentPage,
                        description: data.description,
                        confirmed: '1'
                    });
                    actionsCell.innerHTML = '<span class="ai-audit-badge ai-audit-badge--ok">' + escapeHtml(t('ai.audit_applied')) + '</span>';
                } catch (error) {
                    applyButton.disabled = false;
                    showToast(error.message, 'error');
                }
                }, {
                    confirmText: t('ai.audit_apply'),
                    confirmClass: 'btn btn-primary'
                });
            });
            actionsCell.appendChild(applyButton);
        } catch (error) {
            button.disabled = false;
            button.textContent = t('ai.audit_suggest');
            showToast(error.message, 'error');
        }
    }

	    var aiImageForm = document.getElementById('aiImageForm');
	    var aiImageBusy = false;
	    var AI_IMAGE_REFERENCE_LIMIT = 16;
	    var aiImageReferences = [];
	    var aiImageHistoryOffset = 0;
	    var AI_IMAGE_HISTORY_LIMIT = 12;
	    var aiImageJobPollTimer = null;
	    var aiImageKnownFinishedJobs = new Set();
	    var aiImageRunningJobs = new Set();
	    var AI_IMAGE_JOB_NOTICE_STORAGE_KEY = 'nibbly.aiImageJobNotices.v1';
	    var AI_IMAGE_JOB_NOTICE_LIMIT = 80;

    function aiImageJobNoticeKey(job) {
        if (!job || !job.id) return '';
        return [job.id, job.status || '', job.finishedAt || job.updatedAt || ''].join(':');
    }

    function readAiImageJobNotices() {
        try {
            var raw = window.localStorage ? window.localStorage.getItem(AI_IMAGE_JOB_NOTICE_STORAGE_KEY) : '';
            var notices = raw ? JSON.parse(raw) : {};
            return notices && typeof notices === 'object' ? notices : {};
        } catch (error) {
            return {};
        }
    }

    function writeAiImageJobNotices(notices) {
        if (!window.localStorage) return;
        try {
            var entries = Object.entries(notices || {})
                .sort(function(a, b) { return Number(b[1] || 0) - Number(a[1] || 0); })
                .slice(0, AI_IMAGE_JOB_NOTICE_LIMIT);
            window.localStorage.setItem(AI_IMAGE_JOB_NOTICE_STORAGE_KEY, JSON.stringify(Object.fromEntries(entries)));
        } catch (error) {
            // Notification history is only a UI convenience.
        }
    }

    function aiImageJobNoticeWasShown(job) {
        var key = aiImageJobNoticeKey(job);
        if (!key) return false;
        return !!readAiImageJobNotices()[key];
    }

    function markAiImageJobNoticeShown(job) {
        var key = aiImageJobNoticeKey(job);
        if (!key) return;
        var notices = readAiImageJobNotices();
        notices[key] = Date.now();
        writeAiImageJobNotices(notices);
    }

    function setAiImageBusy(isBusy, activeButton, loadingTextKey) {
        aiImageBusy = isBusy;
        var buttons = [
            document.querySelector('#aiImageForm button[type="submit"]'),
            document.getElementById('aiImproveImagePrompt')
        ];
        buttons.forEach(function(button) {
            if (!button) return;
            button.disabled = isBusy || !dashboardAiImageUsable;
            if (button === activeButton) {
                if (isBusy) {
                    button.dataset.originalHtml = button.dataset.originalHtml || button.innerHTML;
                    button.classList.add('is-loading');
                    button.setAttribute('aria-busy', 'true');
                    button.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span><span>' + escapeHtml(t(loadingTextKey || 'ai.generating')) + '</span>';
                } else {
                    button.classList.remove('is-loading');
                    button.setAttribute('aria-busy', 'false');
                    if (button.dataset.originalHtml) {
                        button.innerHTML = button.dataset.originalHtml;
                        delete button.dataset.originalHtml;
                    }
                }
            }
        });
    }

    function renderAiImageResult(result, target) {
        if (!target || !result) return;
        var paths = Array.isArray(result.paths) ? result.paths : [result.path];
        paths = paths.filter(Boolean);
        if (!paths.length) {
            target.innerHTML = '<div class="ai-image-message ai-image-message--error" role="alert"><strong>Error</strong><span>' + escapeHtml(t('ai.image_history_error')) + '</span></div>';
            return;
        }
        target.innerHTML = '<div class="ai-image-gallery">' + paths.map(function(path) {
            var filename = (path || '').split('/').pop();
            return '<figure class="ai-image-figure"><button type="button" class="ai-image-preview-btn" data-ai-preview="' + escapeHtml(path) + '" data-ai-preview-name="' + escapeHtml(filename) + '"><img src="' + escapeHtml(path) + '" alt=""></button><figcaption><button type="button" class="btn btn-secondary btn-sm" onclick="switchTab(&quot;media&quot;)">' + escapeHtml(t('nav.media_library')) + '</button></figcaption></figure>';
        }).join('') + '</div>';
    }

    function setAiImageJobMessage(target, job, messageKey) {
        if (!target) return;
        var prompt = String((job && job.prompt) || '').trim();
        target.innerHTML = '<div class="ai-image-message" role="status">'
            + '<strong>' + escapeHtml(t(messageKey || 'ai.image_job_queued')) + '</strong>'
            + (prompt ? '<span class="ai-image-message__prompt">' + escapeHtml(prompt) + '</span>' : '')
            + '</div>';
    }

    function setAiImageJobsChecking(isChecking) {
        var button = document.getElementById('aiImageJobsCheck');
        if (!button) return;
        button.disabled = isChecking || !dashboardAiImageUsable;
        button.classList.toggle('is-loading', !!isChecking);
        button.setAttribute('aria-busy', isChecking ? 'true' : 'false');
    }

    function formatAiImageJobTime(value) {
        if (!value) return '';
        var date = new Date(value);
        return isNaN(date.getTime()) ? '' : date.toLocaleString();
    }

    function updateAiImageJobsPanel(jobs, isChecking) {
        var panel = document.getElementById('aiImageJobsPanel');
        var status = document.getElementById('aiImageJobsStatus');
        var meta = document.getElementById('aiImageJobsMeta');
        if (!panel || !status) return;
        panel.hidden = !dashboardAiImageUsable;
        setAiImageJobsChecking(!!isChecking);
        jobs = Array.isArray(jobs) ? jobs : [];
        var openJobs = jobs.filter(function(job) {
            return job && (job.status === 'queued' || job.status === 'running');
        });
        var latest = jobs[0] || null;
        if (openJobs.length) {
            status.textContent = t('ai.image_jobs_open', { count: openJobs.length });
            if (meta) {
                meta.hidden = false;
                meta.textContent = openJobs[0].prompt || '';
            }
            return;
        }
        if (latest && latest.status === 'success') {
            status.textContent = t('ai.image_jobs_finished');
            if (meta) {
                meta.hidden = false;
                meta.textContent = formatAiImageJobTime(latest.finishedAt || latest.updatedAt);
            }
            return;
        }
        if (latest && latest.status === 'error') {
            status.textContent = t('ai.image_jobs_failed');
            if (meta) {
                meta.hidden = false;
                meta.textContent = latest.error || formatAiImageJobTime(latest.finishedAt || latest.updatedAt);
            }
            return;
        }
        status.textContent = t('ai.image_jobs_idle');
        if (meta) {
            meta.hidden = true;
            meta.textContent = '';
        }
    }

    async function runAiImageJob(job) {
        if (!job || !job.id || aiImageRunningJobs.has(job.id)) return;
        aiImageRunningJobs.add(job.id);
        try {
            var formData = new FormData();
            formData.append('action', 'ai-image-job-run');
            formData.append('job_id', job.id);
            formData.append('csrf_token', CSRF_TOKEN);
            await fetch('api.php', { method: 'POST', body: formData, cache: 'no-store' });
        } finally {
            aiImageRunningJobs.delete(job.id);
        }
    }

    async function pollAiImageJobs(activeJobId, target, options) {
        options = options || {};
        updateAiImageJobsPanel(null, !!options.manual);
        try {
            var params = new URLSearchParams({
                action: 'ai-image-jobs',
                open_only: '0',
                limit: '30',
                csrf_token: CSRF_TOKEN
            });
            var response = await fetch('api.php?' + params.toString(), { cache: 'no-store' });
            var result = await response.json();
            if (!result.success) throw new Error(result.message || 'Error');
            var jobs = Array.isArray(result.data.jobs) ? result.data.jobs : [];
            var openJobs = jobs.filter(function(job) {
                return job.status === 'queued' || job.status === 'running';
            });
            updateAiImageJobsPanel(jobs, false);
            var runningJobs = openJobs.filter(function(job) { return job.status === 'running'; });
            var queuedJobs = openJobs.filter(function(job) { return job.status === 'queued'; });
            if (!runningJobs.length && queuedJobs.length) {
                runAiImageJob(queuedJobs[queuedJobs.length - 1]);
            }

            jobs.forEach(function(job) {
                if (!job || !job.id || (job.status !== 'success' && job.status !== 'error')) return;
                var noticeAlreadyShown = aiImageKnownFinishedJobs.has(job.id) || aiImageJobNoticeWasShown(job);
                var isActiveJob = job.id === activeJobId;
                var isPassivePoll = !activeJobId && !target && !options.manual;
                aiImageKnownFinishedJobs.add(job.id);
                if (job.status === 'success') {
                    if (!noticeAlreadyShown && !isPassivePoll) {
                        showToast(t('ai.image_job_finished'), 'success');
                    }
                    if (isActiveJob && target) {
                        renderAiImageResult(job.result, target);
                        updateAiUsage(job.result && job.result.limits ? job.result.limits : null);
                    }
                    loadAiImageHistory(true);
                } else {
                    if (!noticeAlreadyShown && !isPassivePoll) {
                        showToast(job.error || t('ai.image_job_failed'), 'error');
                    }
                    if (isActiveJob && target) {
                        target.innerHTML = '<div class="ai-image-message ai-image-message--error" role="alert"><strong>Error</strong><span>' + escapeHtml(job.error || t('ai.image_job_failed')) + '</span></div>';
                    }
                    loadAiImageHistory(true);
                }
                markAiImageJobNoticeShown(job);
            });

            if (openJobs.length) {
                aiImageJobPollTimer = window.setTimeout(function() {
                    pollAiImageJobs(activeJobId, target);
                }, 12000);
            } else {
                aiImageJobPollTimer = null;
            }
        } catch (error) {
            setAiImageJobsChecking(false);
            aiImageJobPollTimer = window.setTimeout(function() {
                pollAiImageJobs(activeJobId, target);
            }, 15000);
        }
    }

    function startAiImageJobPolling(activeJobId, target, options) {
        if (aiImageJobPollTimer) {
            window.clearTimeout(aiImageJobPollTimer);
            aiImageJobPollTimer = null;
        }
        pollAiImageJobs(activeJobId || '', target || null, options || {});
    }

    document.getElementById('aiImageJobsCheck')?.addEventListener('click', function() {
        startAiImageJobPolling('', null, { manual: true });
    });

    if (aiImageForm) {
        aiImageForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var prompt = document.getElementById('aiImagePrompt').value.trim();
            if (!prompt || aiImageBusy) return;
            var target = document.getElementById('aiImageResult');
            var btn = aiImageForm.querySelector('button[type="submit"]');
            setAiImageBusy(true, btn, 'ai.generating');
            target.textContent = t('ai.generating');
            try {
                var formData = new FormData();
                formData.append('action', 'ai-generate-image');
                formData.append('prompt', prompt);
                formData.append('model', document.getElementById('aiImageModelPicker')?.value || document.getElementById('aiImageModel')?.value || '');
                formData.append('size', document.getElementById('aiImageSize').value);
                formData.append('aspectRatio', document.getElementById('aiImageRatio')?.value || 'auto');
                formData.append('imageScale', document.getElementById('aiImageScale')?.value || '2048');
                formData.append('count', document.getElementById('aiImageCount').value);
                formData.append('outputFormat', document.getElementById('aiImageFormat').value);
                formData.append('quality', document.getElementById('aiImageQuality').value);
                formData.append('moderation', document.getElementById('aiImageModeration').value);
                formData.append('outputCompression', document.getElementById('aiImageCompression').value);
	                aiImageReferences.slice(0, AI_IMAGE_REFERENCE_LIMIT).forEach(function(reference) {
	                    if (reference.type === 'file' && reference.file) {
	                        formData.append('referenceImages[]', reference.file, reference.name || reference.file.name || 'reference-image');
	                    } else if (reference.type === 'media' && reference.path) {
	                        formData.append('referenceMediaPaths[]', reference.path);
	                    }
	                });
                formData.append('filenameHint', aiImageFilenameHintValue());
                formData.append('csrf_token', CSRF_TOKEN);
                var result = await fetchJsonWithTimeout('api.php', { method: 'POST', body: formData }, 45000);
                if (!result.success) throw new Error(result.message);
                if (result.data && result.data.job) {
                    setAiImageJobMessage(target, result.data.job, 'ai.image_job_queued');
                    runAiImageJob(result.data.job);
                    startAiImageJobPolling(result.data.job.id, target);
                } else {
                    renderAiImageResult(result.data, target);
                    updateAiUsage(result.data.limits);
                    loadAiImageHistory(true);
                }
            } catch (error) {
                var message = error && error.name === 'AbortError'
                    ? t('copilot.image_request_timeout')
                    : (error.message || t('toast.error'));
                target.innerHTML = '<div class="ai-image-message ai-image-message--error" role="alert"><strong>Error</strong><span>' + escapeHtml(message) + '</span></div>';
            } finally {
                setAiImageBusy(false, btn);
            }
        });
    }

    var aiImageResult = document.getElementById('aiImageResult');
    if (aiImageResult) {
        aiImageResult.addEventListener('click', function(e) {
            var trigger = e.target.closest('[data-ai-preview]');
            if (!trigger || !aiImageResult.contains(trigger)) return;
            if (window.NbImageManager && typeof NbImageManager.preview === 'function') {
                NbImageManager.preview(trigger.dataset.aiPreview, trigger.dataset.aiPreviewName || '');
            } else {
                window.open(trigger.dataset.aiPreview, '_blank', 'noopener');
            }
        });
    }

    function formatAiHistoryDate(value) {
        if (!value) return '';
        var date = new Date(value);
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleString();
    }

    function renderAiImageHistory(items, append, hasMore) {
        var container = document.getElementById('aiImageHistory');
        var list = document.getElementById('aiImageHistoryList');
        var more = document.getElementById('aiImageHistoryLoadMore');
        if (!container || !list) return;
        var safeItems = Array.isArray(items) ? items : [];
        if (!append) {
            list.innerHTML = '';
        }
        if (!append && !safeItems.length) {
            list.innerHTML = '<p class="ai-image-history-empty">' + escapeHtml(t('ai.image_history_empty')) + '</p>';
        } else {
            safeItems.forEach(function(item) {
                var outputs = Array.isArray(item.outputs) ? item.outputs : [];
                var firstOutput = outputs[0] || '';
                var prompt = item.prompt || '';
                var status = item.status === 'error' ? 'error' : 'success';
                var statusLabel = status === 'error' ? t('ai.image_history_status_error') : t('ai.image_history_status_success');
                var multiThumb = outputs.length > 1;
                var thumb;
                if (multiThumb) {
                    thumb = '<div class="ai-image-history-card__thumbs">' +
                        outputs.map(function(path) {
                            return '<button type="button" class="ai-image-history-card__thumb" data-ai-preview="' + escapeHtml(path) + '" data-ai-preview-name="' + escapeHtml(path.split('/').pop()) + '"><img src="' + escapeHtml(path) + '" alt=""></button>';
                        }).join('') +
                        '</div>';
                } else if (firstOutput) {
                    thumb = '<button type="button" class="ai-image-history-card__thumb" data-ai-preview="' + escapeHtml(firstOutput) + '" data-ai-preview-name="' + escapeHtml(firstOutput.split('/').pop()) + '"><img src="' + escapeHtml(firstOutput) + '" alt=""></button>';
                } else {
                    thumb = '<div class="ai-image-history-card__thumb ai-image-history-card__thumb--empty">' + escapeHtml(t('ai.image_history_error')) + '</div>';
                }
                var ratioMeta = item.aspectRatio && item.aspectRatio !== 'auto' ? item.aspectRatio : '';
                // Display pixel dimensions with a proper multiplication sign (e.g. 2560×1440).
                var sizeMeta = /^\d+x\d+$/.test(String(item.size || '')) ? String(item.size).replace('x', '×') : (item.size || '');
                var formatMeta = item.format ? String(item.format).toUpperCase() : '';
                var meta = [item.model, ratioMeta, sizeMeta, formatMeta, item.quality].filter(Boolean).join(' · ');
                var html =
                    '<article class="ai-image-history-card ai-image-history-card--' + status + (multiThumb ? ' ai-image-history-card--multi' : '') + '">' +
                        thumb +
                        '<div class="ai-image-history-card__body">' +
                            '<div class="ai-image-history-card__top">' +
                                '<span class="ai-image-history-status">' + escapeHtml(statusLabel) + '</span>' +
                                '<time>' + escapeHtml(formatAiHistoryDate(item.createdAt)) + '</time>' +
                            '</div>' +
                            '<p class="ai-image-history-prompt">' + escapeHtml(prompt || (item.error || '')) + '</p>' +
                            '<p class="ai-image-history-meta">' + escapeHtml(meta) + '</p>' +
                            '<div class="ai-image-history-actions">' +
                                '<button type="button" class="btn btn-secondary btn-sm" data-ai-history-prompt="' + escapeHtml(prompt) + '">' + escapeHtml(t('ai.image_history_use_prompt')) + '</button>' +
                                (firstOutput ? '<button type="button" class="btn btn-secondary btn-sm" onclick="switchTab(&quot;media&quot;)">' + escapeHtml(t('nav.media_library')) + '</button>' : '') +
                            '</div>' +
                        '</div>' +
                    '</article>';
                list.insertAdjacentHTML('beforeend', html);
            });
        }
        container.hidden = false;
        if (more) {
            more.hidden = !hasMore;
        }
    }

    async function loadAiImageHistory(reset) {
        if (!document.getElementById('aiImageHistoryList')) return;
        var offset = reset ? 0 : aiImageHistoryOffset;
        try {
            var params = new URLSearchParams({
                action: 'ai-image-history',
                offset: String(offset),
                limit: String(AI_IMAGE_HISTORY_LIMIT),
                csrf_token: CSRF_TOKEN
            });
            var response = await fetch('api.php?' + params.toString());
            var result = await response.json();
            if (!result.success) throw new Error(result.message || 'Error');
            aiImageHistoryOffset = (result.data.offset || 0) + (Array.isArray(result.data.items) ? result.data.items.length : 0);
            renderAiImageHistory(result.data.items || [], !reset, !!result.data.hasMore);
        } catch (error) {
            showToast(error.message, 'error');
        }
    }

    var aiImageHistoryList = document.getElementById('aiImageHistoryList');
    if (aiImageHistoryList) {
        aiImageHistoryList.addEventListener('click', function(e) {
            var preview = e.target.closest('[data-ai-preview]');
            if (preview && aiImageHistoryList.contains(preview)) {
                if (window.NbImageManager && typeof NbImageManager.preview === 'function') {
                    NbImageManager.preview(preview.dataset.aiPreview, preview.dataset.aiPreviewName || '');
                } else {
                    window.open(preview.dataset.aiPreview, '_blank', 'noopener');
                }
                return;
            }
            var promptButton = e.target.closest('[data-ai-history-prompt]');
            if (promptButton && aiImageHistoryList.contains(promptButton)) {
                var promptField = document.getElementById('aiImagePrompt');
                if (promptField) {
                    promptField.value = promptButton.dataset.aiHistoryPrompt || '';
                    promptField.focus();
                }
            }
        });
    }

    var aiImageHistoryLoadMore = document.getElementById('aiImageHistoryLoadMore');
    if (aiImageHistoryLoadMore) {
        aiImageHistoryLoadMore.addEventListener('click', function() {
            loadAiImageHistory(false);
        });
    }

    var aiImageHistoryClear = document.getElementById('aiImageHistoryClear');
    if (aiImageHistoryClear) {
        aiImageHistoryClear.addEventListener('click', function() {
            showModal(t('ai.image_history_clear'), t('ai.image_history_clear_confirm'), async function() {
                closeModal();
            try {
                var formData = new FormData();
                formData.append('action', 'clear-ai-image-history');
                formData.append('csrf_token', CSRF_TOKEN);
                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();
                if (!result.success) throw new Error(result.message || 'Error');
                aiImageHistoryOffset = 0;
                renderAiImageHistory([], false, false);
                showToast(t('ai.image_history_cleared'), 'success');
            } catch (error) {
                showToast(error.message, 'error');
            }
            }, {
                confirmText: t('ai.image_history_clear'),
                confirmClass: 'btn btn-danger'
            });
        });
    }

    if (document.getElementById('aiImageHistoryList')) {
        loadAiImageHistory(true);
        startAiImageJobPolling('', null);
    }

	    var aiImageReference = document.getElementById('aiImageReference');
	    var aiImageReferenceUpload = document.getElementById('aiImageReferenceUpload');
	    var aiImageReferenceLibrary = document.getElementById('aiImageReferenceLibrary');
	    var aiImageReferenceClear = document.getElementById('aiImageReferenceClear');
	    if (aiImageReferenceUpload && aiImageReference) {
	        aiImageReferenceUpload.addEventListener('click', function() {
	            aiImageReference.click();
	        });
	        aiImageReference.addEventListener('change', function() {
	            addAiImageReferenceFiles(Array.from(aiImageReference.files || []));
	            aiImageReference.value = '';
	        });
	    }
	    if (aiImageReferenceLibrary) {
	        aiImageReferenceLibrary.addEventListener('click', function() {
	            if (!window.NbImageManager) return;
	            NbImageManager.open(function(paths) {
	                if (aiImageReference) aiImageReference.value = '';
	                addAiImageReferenceMedia(Array.isArray(paths) ? paths : [paths]);
	            }, aiImageReferences.filter(function(item) {
	                return item.type === 'media';
	            }).map(function(item) {
	                return item.path;
	            }), { types: ['image'], type: 'image', multiple: true });
	        });
	    }
	    if (aiImageReferenceClear) {
	        aiImageReferenceClear.addEventListener('click', function() {
	            if (aiImageReference) aiImageReference.value = '';
	            aiImageReferences = [];
	            updateAiImageReferences();
	        });
	    }

	    function addAiImageReferenceFiles(files) {
	        files.forEach(function(file) {
	            if (!file || aiImageReferences.length >= AI_IMAGE_REFERENCE_LIMIT) return;
	            aiImageReferences.push({
	                type: 'file',
	                file: file,
	                name: file.name || t('ai.image_reference_file')
	            });
	        });
	        updateAiImageReferences();
	    }

	    function addAiImageReferenceMedia(paths) {
	        paths.forEach(function(path) {
	            if (!path || aiImageReferences.length >= AI_IMAGE_REFERENCE_LIMIT) return;
	            path = String(path);
	            var duplicate = aiImageReferences.some(function(item) {
	                return item.type === 'media' && item.path === path;
	            });
	            if (duplicate) return;
	            aiImageReferences.push({
	                type: 'media',
	                path: path,
	                name: path.split('/').pop() || path
	            });
	        });
	        updateAiImageReferences();
	    }

	    function removeAiImageReference(index) {
	        aiImageReferences.splice(index, 1);
	        updateAiImageReferences();
	    }

	    function updateAiImageReferences() {
	        var label = document.getElementById('aiImageReferenceName');
	        var clear = document.getElementById('aiImageReferenceClear');
	        var list = document.getElementById('aiImageReferenceList');
	        var count = aiImageReferences.length;
	        if (label) {
	            label.textContent = count
	                ? t('ai.image_reference_count', { count: count, max: AI_IMAGE_REFERENCE_LIMIT })
	                : t('ai.image_reference_none');
	        }
	        if (clear) clear.hidden = count === 0;
	        if (!list) return;
	        list.hidden = count === 0;
	        list.innerHTML = aiImageReferences.map(function(reference, index) {
	            var preview = '';
	            if (reference.type === 'file' && reference.file) {
	                preview = URL.createObjectURL(reference.file);
	            } else if (reference.type === 'media') {
	                preview = reference.path;
	            }
	            return '<span class="ai-reference-chip">' +
	                (preview ? '<span class="ai-reference-chip__thumb" style="background-image:url(&quot;' + escapeHtml(preview) + '&quot;)"></span>' : '') +
	                '<span class="ai-reference-chip__name">' + escapeHtml(reference.name || t('ai.image_reference_file')) + '</span>' +
	                '<button type="button" class="ai-reference-chip__remove" data-reference-index="' + index + '" aria-label="' + escapeHtml(t('ai.image_reference_remove')) + '">&times;</button>' +
	            '</span>';
	        }).join('');
	    }

	    document.getElementById('aiImageReferenceList')?.addEventListener('click', function(e) {
	        var button = e.target.closest('[data-reference-index]');
	        if (!button) return;
	        removeAiImageReference(parseInt(button.dataset.referenceIndex, 10));
	    });

	    updateAiImageReferences();

    // Curated per-image prices (cents) for OpenAI image models, since the
    // OpenAI-compatible endpoint has no model price API. Declared before the
    // size picker initialises (which computes the estimated cost on load).
    var AI_OPENAI_IMAGE_COST_CENTS = {
        'gpt-image-2': 4
    };

    initAiImageSizePicker();
    document.getElementById('aiImageSize')?.addEventListener('change', updateAiImageRatioIcon);
    updateAiImageRatioIcon();

    var aiCompressionSlider = document.getElementById('aiImageCompression');
    var aiCompressionValue = document.getElementById('aiImageCompressionValue');
    var aiCompressionFill = document.getElementById('aiImageCompressionFill');
    if (aiCompressionSlider && aiCompressionValue && aiCompressionFill) {
        var syncAiCompression = function() {
            var pct = Math.max(0, Math.min(100, parseInt(aiCompressionSlider.value || '0', 10)));
            aiCompressionFill.style.width = pct + '%';
            aiCompressionValue.textContent = pct + '%';
            // Value sits inside the filled area; below 40% the fill is too
            // narrow, so flip the label to the empty (right) side.
            aiCompressionValue.classList.toggle('fill-slider__value--right', pct < 40);
        };
        aiCompressionSlider.addEventListener('input', syncAiCompression);
        syncAiCompression();
    }

    function initAiImageSizePicker() {
        var select = document.getElementById('aiImageSize');
        var ratioInput = document.getElementById('aiImageRatio');
        var trigger = document.getElementById('aiImageSizeTrigger');
        var menu = document.getElementById('aiImageSizeMenu');
        if (!select || !trigger || !menu) return;
        var options = [
            { group: t('ai.image_group_auto'), value: 'auto', ratio: 'auto', name: t('ai.image_size_auto') },
            { group: t('ai.image_group_square'), value: '1:1', ratio: '1:1', name: 'Square' },
            { group: t('ai.image_group_landscape'), value: '5:4', ratio: '5:4', name: 'Classic' },
            { group: t('ai.image_group_landscape'), value: '4:3', ratio: '4:3', name: 'Classic' },
            { group: t('ai.image_group_landscape'), value: '3:2', ratio: '3:2', name: 'Standard' },
            { group: t('ai.image_group_landscape'), value: '16:9', ratio: '16:9', name: 'Widescreen' },
            { group: t('ai.image_group_landscape'), value: '21:9', ratio: '21:9', name: 'Ultrawide' },
            { group: t('ai.image_group_portrait'), value: '4:5', ratio: '4:5', name: 'Classic' },
            { group: t('ai.image_group_portrait'), value: '3:4', ratio: '3:4', name: 'Traditional' },
            { group: t('ai.image_group_portrait'), value: '2:3', ratio: '2:3', name: 'Portrait' },
            { group: t('ai.image_group_portrait'), value: '9:16', ratio: '9:16', name: 'Social story' }
        ];
        var currentGroup = '';
        menu.innerHTML = options.map(function(option) {
            var groupHtml = '';
            if (option.group !== currentGroup) {
                currentGroup = option.group;
                groupHtml = '<div class="ai-size-group">' + escapeHtml(option.group) + '</div>';
            }
            return groupHtml + '<button type="button" class="ai-size-option" role="option" data-ratio="' + escapeHtml(option.value) + '">' +
                '<span class="ai-size-option-icon" data-ratio="' + escapeHtml(option.value) + '" aria-hidden="true"></span>' +
                '<span class="ai-size-option-ratio">' + escapeHtml(option.ratio) + '</span>' +
                '<span class="ai-size-option-name">' + escapeHtml(option.name) + '</span>' +
            '</button>';
        }).join('');
        menu.querySelectorAll('.ai-size-option-icon').forEach(function(iconEl) {
            applyAiRatioIcon(iconEl, iconEl.dataset.ratio || 'auto');
        });
        trigger.addEventListener('click', function() {
            if (trigger.disabled) return;
            var isOpen = !menu.hidden;
            menu.hidden = isOpen;
            trigger.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        });
        menu.addEventListener('click', function(e) {
            var option = e.target.closest('.ai-size-option');
            if (!option) return;
            if (ratioInput) ratioInput.value = option.dataset.ratio || 'auto';
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            updateAiImageRatioIcon();
        });
        document.addEventListener('click', function(e) {
            if (!menu.hidden && !document.getElementById('aiImageSizePicker')?.contains(e.target)) {
                menu.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
        updateAiImageRatioIcon();
    }

    function updateAiImageRatioIcon() {
        var select = document.getElementById('aiImageSize');
        var ratioInput = document.getElementById('aiImageRatio');
        var icon = document.getElementById('aiImageRatioIcon');
        var label = document.getElementById('aiImageSizeLabel');
        var menu = document.getElementById('aiImageSizeMenu');
        var scale = document.getElementById('aiImageScale');
        var note = document.getElementById('aiImageSizeNote');
        if (!select || !icon) return;
        var ratioValue = ratioInput ? ratioInput.value : 'auto';
        var computedSize = computeAiImageSize(ratioValue, scale ? scale.value : '2048');
        select.value = computedSize.size;
        if (label) {
            var selected = null;
            if (menu) {
                menu.querySelectorAll('.ai-size-option').forEach(function(option) {
                    if (option.dataset.ratio === ratioValue) selected = option;
                });
            }
            if (selected) {
                var ratioText = selected.querySelector('.ai-size-option-ratio')?.textContent || '';
                var nameText = selected.querySelector('.ai-size-option-name')?.textContent || '';
                label.innerHTML = ratioText === 'auto'
                    ? '<span>' + escapeHtml(nameText) + '</span>'
                    : '<span>' + escapeHtml(ratioText) + '</span><span>' + escapeHtml(nameText) + '</span>';
            } else {
                label.textContent = t('ai.image_size_auto');
            }
        }
        if (menu) {
            menu.querySelectorAll('.ai-size-option').forEach(function(option) {
                option.setAttribute('aria-selected', option.dataset.ratio === ratioValue ? 'true' : 'false');
            });
        }
        if (note) {
            var sizeText = computedSize.size === 'auto'
                ? t('ai.image_size_note_auto')
                : t('ai.image_size_note').replace('{size}', computedSize.size.replace('x', ' × '));
            note.textContent = sizeText + aiEstimatedImageCostSuffix();
        }
        applyAiRatioIcon(icon, computedSize.size === 'auto' ? ratioValue : computedSize.size);
    }

    // Detect the active provider from the saved settings only (not the
    // settings-form DOM), since cost resolution runs in the image generator
    // where the relevant provider is the configured one.
    function aiSavedProviderKey() {
        var settings = currentAiSettings || {};
        var provider = String(settings.provider || '').trim();
        var baseUrl = String(settings.baseUrl || '').trim();
        if (provider === 'anthropic' || baseUrl.indexOf('api.anthropic.com') !== -1) return 'anthropic';
        if (provider === 'kie' || baseUrl.indexOf('api.kie.ai') !== -1) return 'kie';
        if (provider === 'openrouter' || baseUrl.indexOf('openrouter.ai') !== -1) return 'openrouter';
        return 'openai-compatible';
    }

    // Resolve the estimated per-image cost for the selected provider/model.
    // Returns { cents, estimated } or null when no figure is available.
    function aiResolveImageCost() {
        var settings = currentAiSettings || {};
        var providerKey = aiSavedProviderKey();
        if (providerKey === 'anthropic') {
            return null; // Anthropic does not generate images.
        }
        var model = String(document.getElementById('aiImageModelPicker')?.value
            || document.getElementById('aiImageModel')?.value
            || settings.imageModel || '').trim();
        var configuredCents = parseInt((settings.pricing && settings.pricing.imageCentsPerRequest) || 0, 10);

        if (providerKey === 'openrouter') {
            var entry = aiOpenRouterModelsCache && Array.isArray(aiOpenRouterModelsCache.imageModels)
                ? aiOpenRouterModelsCache.imageModels.find(function(m) { return m.id === model; })
                : null;
            if (entry && entry.imageCostCents != null) {
                return { cents: entry.imageCostCents, estimated: !!entry.imageCostEstimated };
            }
            // Catalog not loaded yet or model not found: fall back to settings.
            return configuredCents ? { cents: configuredCents, estimated: true } : null;
        }

        if (providerKey === 'kie') {
            var kieCosts = {
                'gpt-image-2': 5,
                'nano-banana-2': 4,
                'seedream-5-0-pro': 5
            };
            if (kieCosts[model] != null) {
                return { cents: kieCosts[model], estimated: true };
            }
            return configuredCents ? { cents: configuredCents, estimated: true } : null;
        }

        // OpenAI-compatible: curated default for known models, else settings.
        if (AI_OPENAI_IMAGE_COST_CENTS[model] != null) {
            return { cents: AI_OPENAI_IMAGE_COST_CENTS[model], estimated: false };
        }
        return configuredCents ? { cents: configuredCents, estimated: false } : null;
    }

    // Format a (possibly fractional) cents value as EUR with enough precision
    // for sub-cent image prices: 2 decimals normally, more when very small.
    function formatAiImageCents(cents) {
        var eur = (parseFloat(cents) || 0) / 100;
        var decimals = eur >= 0.01 ? 2 : (eur >= 0.001 ? 3 : 4);
        return eur.toFixed(decimals) + ' EUR';
    }

    // " · Estimated cost per image: 0.05 EUR" (with a leading ~ when the figure
    // is an estimate). Empty when no figure is available.
    function aiEstimatedImageCostSuffix() {
        var cost = aiResolveImageCost();
        if (!cost || !cost.cents) return '';
        var amount = (cost.estimated ? '~' : '') + formatAiImageCents(cost.cents);
        return ' · ' + t('ai.image_cost_per_image').replace('{cost}', amount);
    }

    function applyAiRatioIcon(icon, value) {
        var match = String(value || '').match(/^(\d+)x(\d+)$/);
        if (!match) {
            match = String(value || '').match(/^(\d+):(\d+)$/);
        }
        if (!match) {
            icon.style.setProperty('--ratio-w', '1');
            icon.style.setProperty('--ratio-h', '1');
            icon.classList.add('ai-ratio-icon--auto');
            return;
        }
        icon.classList.remove('ai-ratio-icon--auto');
        var w = parseInt(match[1], 10);
        var h = parseInt(match[2], 10);
        var ratio = w / h;
        icon.style.setProperty('--ratio-w', ratio >= 1 ? Math.min(2.4, ratio) : 1);
        icon.style.setProperty('--ratio-h', ratio >= 1 ? 1 : Math.min(2.4, 1 / ratio));
    }

    function computeAiImageSize(ratioValue, scaleValue) {
        if (!ratioValue || ratioValue === 'auto') {
            return { size: 'auto' };
        }
        var parts = ratioValue.split(':').map(function(part) { return parseInt(part, 10); });
        if (parts.length !== 2 || !parts[0] || !parts[1]) {
            return { size: 'auto' };
        }
        var rw = parts[0];
        var rh = parts[1];
        var targetLong = parseInt(scaleValue, 10) || 2048;
        var ratio = Math.max(rw, rh) / Math.min(rw, rh);
        var minPixels = 655360;
        var maxPixels = 8294400;
        var longEdge = Math.min(3840, Math.max(16, targetLong));
        var minLongForPixels = Math.ceil(Math.sqrt(minPixels * ratio) / 16) * 16;
        longEdge = Math.max(longEdge, minLongForPixels);
        var shortEdge = Math.round((longEdge / ratio) / 16) * 16;
        if (shortEdge < 16) shortEdge = 16;
        while (longEdge * shortEdge > maxPixels && longEdge > 16) {
            longEdge -= 16;
            shortEdge = Math.round((longEdge / ratio) / 16) * 16;
        }
        while (longEdge * shortEdge < minPixels && longEdge < 3840) {
            longEdge += 16;
            shortEdge = Math.round((longEdge / ratio) / 16) * 16;
        }
        var width = rw >= rh ? longEdge : shortEdge;
        var height = rw >= rh ? shortEdge : longEdge;
        return { size: width + 'x' + height };
    }

    document.getElementById('aiImageScale')?.addEventListener('change', updateAiImageRatioIcon);

    var aiImageCountInput = document.getElementById('aiImageCount');
    var aiImageCountUp = document.getElementById('aiImageCountUp');
    var aiImageCountDown = document.getElementById('aiImageCountDown');
    if (aiImageCountInput) {
        aiImageCountInput.addEventListener('change', function() {
            updateAiImageCount(0);
        });
        aiImageCountInput.addEventListener('input', function() {
            updateAiImageCount(0);
        });
        updateAiImageCount(0);
    }
    if (aiImageForm) {
        aiImageForm.addEventListener('click', function(e) {
            if (e.target.closest('#aiImageCountUp')) {
                e.preventDefault();
                updateAiImageCount(1);
            } else if (e.target.closest('#aiImageCountDown')) {
                e.preventDefault();
                updateAiImageCount(-1);
            }
        });
    }

    function updateAiImageCount(delta) {
        var input = document.getElementById('aiImageCount');
        var button = document.getElementById('aiGenerateImageButton');
        if (!input) return;
        var value = parseInt(input.value, 10) || 1;
        value = Math.max(1, Math.min(10, value + delta));
        input.value = String(value);
        if (button && !button.dataset.originalHtml) {
            button.textContent = value === 1 ? t('ai.generate_image') : t('ai.generate_images');
        }
    }

    function aiImageFilenameSlug(value) {
        value = String(value || '').trim();
        if (!value) return '';
        value = value
            .replace(/[Ä]/g, 'Ae').replace(/[Ö]/g, 'Oe').replace(/[Ü]/g, 'Ue')
            .replace(/[ä]/g, 'ae').replace(/[ö]/g, 'oe').replace(/[ü]/g, 'ue')
            .replace(/[ß]/g, 'ss');
        try {
            value = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        } catch (error) {}
        value = value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        return (value || 'ai-image').substring(0, 72).replace(/-+$/g, '') || 'ai-image';
    }

    function aiImageFilenameHintValue() {
        return String(document.getElementById('aiImageFilenameHint')?.value || '')
            .trim()
            .replace(/\.(png|jpe?g|webp)$/i, '');
    }

    document.getElementById('aiSuggestImageFilename')?.addEventListener('click', function() {
        var prompt = document.getElementById('aiImagePrompt')?.value || '';
        var input = document.getElementById('aiImageFilenameHint');
        if (!input) return;
        input.value = aiImageFilenameSlug(prompt);
        input.focus();
        input.select();
    });

    document.getElementById('aiImproveImagePrompt')?.addEventListener('click', async function() {
        var promptEl = document.getElementById('aiImagePrompt');
        var prompt = promptEl.value.trim();
        if (!prompt || aiImageBusy) return;
        var btn = this;
        setAiImageBusy(true, btn, 'ai.improving_prompt');
        try {
            var formData = new FormData();
            formData.append('action', 'ai-generate-text');
            formData.append('prompt', 'Improve this image generation prompt. Return only the improved prompt, no intro, no markdown. Keep the user intent and make it specific, visual, and concise:\\n\\n' + prompt);
            formData.append('maxOutputTokens', '350');
            formData.append('csrf_token', CSRF_TOKEN);
            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();
            if (!result.success) throw new Error(result.message);
            promptEl.value = String(result.data.text || '').trim();
            updateAiUsage(result.data.limits);
        } catch (error) {
            showToast(error.message, 'error');
        } finally {
            setAiImageBusy(false, btn);
        }
    });

    // ============================================================
    // SAVE LANGUAGE
    // ============================================================

    document.getElementById('languageForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        var btn = document.getElementById('saveLanguageBtn');
        btn.disabled = true;
        btn.textContent = t('btn.saving');

        try {
            var settings = Object.assign({}, currentSettings || {});
            if (!settings.general) settings.general = {};
            settings.general.adminLanguage = document.getElementById('settingsAdminLanguage').value;

            var formData = new FormData();
            formData.append('action', 'save-settings');
            formData.append('settings', JSON.stringify(settings));
            formData.append('csrf_token', CSRF_TOKEN);

            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();

            if (result.success) {
                currentSettings = result.data;
                showToast(t('toast.language_saved'), 'success');
                // Reload to apply new language
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = t('settings.save_language');
        }
    });

    // ============================================================
    // SAVE LOGIN BEHAVIOUR
    // ============================================================

    var loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var btn = document.getElementById('saveLoginBtn');
            btn.disabled = true;
            btn.textContent = t('btn.saving');

            try {
                var settings = Object.assign({}, currentSettings || {});
                if (!settings.general) settings.general = {};
                var modeRadio = document.querySelector('input[name="frontendLoginRedirect"]:checked');
                settings.general.frontendLoginRedirect = modeRadio ? modeRadio.value : 'auto';
                settings.login = {
                    brandAsset: document.getElementById('loginBrandAsset').value,
                    image: document.getElementById('loginImage').value.trim(),
                    imageLayout: document.getElementById('loginImageLayout').value,
                    overlayColor: document.getElementById('loginImageLayout').value === 'background'
                        ? document.getElementById('loginOverlayColor').value
                        : '',
                    overlayOpacity: document.getElementById('loginImageLayout').value === 'background'
                        ? parseInt(document.getElementById('loginOverlayOpacity').value, 10)
                        : 86,
                    boxStyle: document.getElementById('loginBoxStyle').value,
                    boxColor: document.getElementById('loginBoxStyle').value === 'card'
                        ? document.getElementById('loginBoxColor').value
                        : '',
                    boxTextColor: document.getElementById('loginBoxTextColor').value
                };

                var formData = new FormData();
                formData.append('action', 'save-settings');
                formData.append('settings', JSON.stringify(settings));
                formData.append('csrf_token', CSRF_TOKEN);

                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();

                if (result.success) {
                    currentSettings = result.data;
                    showToast(t('toast.login_saved'), 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = t('settings.save_login');
            }
        });
    }

    // ============================================================
    // ACCESS SETTINGS
    // ============================================================

    var accessForm = document.getElementById('accessForm');
    if (accessForm) {
        accessForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var btn = document.getElementById('saveAccessBtn');
            btn.disabled = true;
            btn.textContent = t('btn.saving');

            try {
                var settings = Object.assign({}, currentSettings || {});
                settings.access = settings.access || {};
                settings.access.maintenance = {
                    enabled: document.getElementById('maintenanceEnabled').checked,
                    mode: document.getElementById('maintenanceMode').value,
                    title: document.getElementById('maintenanceTitle').value.trim(),
                    text: document.getElementById('maintenanceText').value.trim(),
                    until: document.getElementById('maintenanceUntil').value,
                    showCountdown: document.getElementById('maintenanceCountdown').checked,
                    brandAsset: document.getElementById('maintenanceBrandAsset').value,
                    image: document.getElementById('maintenanceImage').value.trim(),
                    imageLayout: document.getElementById('maintenanceImageLayout').value,
                    overlayColor: document.getElementById('maintenanceImageLayout').value === 'background'
                        ? document.getElementById('maintenanceOverlayColor').value
                        : '',
                    overlayOpacity: document.getElementById('maintenanceImageLayout').value === 'background'
                        ? parseInt(document.getElementById('maintenanceOverlayOpacity').value, 10)
                        : 88,
                    bypassParam: document.getElementById('maintenanceBypassParam').value.trim() || 'preview',
                    bypassKey: document.getElementById('maintenanceBypassKey').value
                };

                var formData = new FormData();
                formData.append('action', 'save-settings');
                formData.append('settings', JSON.stringify(settings));
                formData.append('csrf_token', CSRF_TOKEN);

                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();

                if (result.success) {
                    currentSettings = result.data;
                    populateSettings(currentSettings);
                    showToast(t('toast.access_saved'), 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = t('settings.save_access');
            }
        });
    }

    var moduleForm = document.getElementById('moduleForm');
    if (moduleForm) {
        moduleForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var btn = document.getElementById('saveModulesBtn');
            btn.disabled = true;
            btn.textContent = t('btn.saving');

            try {
                var settings = Object.assign({}, currentSettings || {});
                var previousModules = JSON.stringify(settings.modules || {});
                settings.modules = settings.modules || {};
                settings.modules.ai = document.getElementById('moduleAi').checked;
                settings.modules.news = document.getElementById('moduleNews').checked;
                settings.modules.events = document.getElementById('moduleEvents').checked;
                settings.modules.messages = document.getElementById('moduleMessages').checked;
                settings.modules.iconManager = document.getElementById('moduleIconManager').checked;

                var formData = new FormData();
                formData.append('action', 'save-settings');
                formData.append('settings', JSON.stringify(settings));
                formData.append('csrf_token', CSRF_TOKEN);

                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();

                if (result.success) {
                    currentSettings = result.data;
                    populateSettings(currentSettings);
                    showToast(t('toast.modules_saved'), 'success');
                    if (JSON.stringify(currentSettings.modules || {}) !== previousModules) {
                        setTimeout(function() { location.reload(); }, 800);
                    }
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = t('settings.save_modules');
            }
        });
    }

    var privacyForm = document.getElementById('privacyForm');
    if (privacyForm) {
        privacyForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            var btn = document.getElementById('savePrivacyBtn');
            btn.disabled = true;
            btn.textContent = t('btn.saving');

            try {
                var settings = Object.assign({}, currentSettings || {});
                settings.privacy = settings.privacy || {};
                settings.privacy.analyticsEnabled = document.getElementById('analyticsEnabled').checked;
                settings.privacy.emailObfuscation = document.getElementById('emailObfuscation').checked;
                settings.privacy.rememberPublicTheme = document.getElementById('rememberPublicTheme').checked;

                var formData = new FormData();
                formData.append('action', 'save-settings');
                formData.append('settings', JSON.stringify(settings));
                formData.append('csrf_token', CSRF_TOKEN);

                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();

                if (result.success) {
                    currentSettings = result.data;
                    populateSettings(currentSettings);
                    showToast(t('toast.privacy_saved'), 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = t('settings.save_privacy');
            }
        });
    }

    // ============================================================
    // EMAIL SETTINGS
    // ============================================================

    function toggleSmtpFields(method) {
        var smtpFields = document.getElementById('smtpFields');
        var sendmailHint = document.getElementById('sendmailHint');
        var emailFields = document.querySelectorAll('#settingsRecipientEmail, #settingsFromEmail, #settingsFromName');
        var inactiveHint = document.getElementById('emailInactiveHint');

        smtpFields.style.display = 'none';
        sendmailHint.style.display = 'none';
        if (inactiveHint) inactiveHint.style.display = 'none';

        // Show/hide all email config fields
        var fieldGroups = smtpFields.parentElement.querySelectorAll('.form-group');
        for (var i = 1; i < fieldGroups.length; i++) { // skip method dropdown
            fieldGroups[i].style.display = (method === 'inactive') ? 'none' : '';
        }
        smtpFields.style.display = (method === 'smtp') ? '' : 'none';

        if (method === 'sendmail') {
            sendmailHint.style.display = '';
        } else if (method === 'inactive') {
            if (inactiveHint) inactiveHint.style.display = '';
        }
    }

    document.getElementById('settingsEmailMethod').addEventListener('change', function() {
        toggleSmtpFields(this.value);
    });

    document.getElementById('settingsSmtpEncryption').addEventListener('change', function() {
        var portField = document.getElementById('settingsSmtpPort');
        if (this.value === 'ssl') portField.value = 465;
        else if (this.value === 'tls') portField.value = 587;
        else portField.value = 25;
    });

    function getEmailFormData() {
        return {
            method: document.getElementById('settingsEmailMethod').value,
            recipientEmail: document.getElementById('settingsRecipientEmail').value.trim(),
            bccEmail: document.getElementById('settingsBccEmail').value.trim(),
            fromEmail: document.getElementById('settingsFromEmail').value.trim(),
            fromName: document.getElementById('settingsFromName').value.trim(),
            smtpHost: document.getElementById('settingsSmtpHost').value.trim(),
            smtpPort: parseInt(document.getElementById('settingsSmtpPort').value, 10) || 587,
            smtpUsername: document.getElementById('settingsSmtpUsername').value.trim(),
            smtpPassword: document.getElementById('settingsSmtpPassword').value,
            smtpEncryption: document.getElementById('settingsSmtpEncryption').value
        };
    }

    document.getElementById('emailForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        var btn = document.getElementById('saveEmailBtn');
        btn.disabled = true;
        btn.textContent = t('btn.saving');

        try {
            var emailData = getEmailFormData();
            var settings = Object.assign({}, currentSettings || {});
            settings.email = emailData;

            var formData = new FormData();
            formData.append('action', 'save-settings');
            formData.append('settings', JSON.stringify(settings));
            formData.append('csrf_token', CSRF_TOKEN);

            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();

            if (result.success) {
                currentSettings = result.data;
                showToast(t('toast.email_saved'), 'success');
                // Update password placeholder to indicate saved
                if (emailData.smtpPassword || currentSettings.email?.smtpPassword) {
                    document.getElementById('settingsSmtpPassword').value = '';
                    document.getElementById('settingsSmtpPassword').placeholder = '••••••••';
                }
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = t('settings.save_email');
        }
    });

    document.getElementById('testEmailBtn').addEventListener('click', async function() {
        var btn = this;
        var resultEl = document.getElementById('emailTestResult');
        btn.disabled = true;
        btn.textContent = t('settings.testing_email');
        resultEl.style.display = 'none';

        var emailData = getEmailFormData();
        if (!emailData.recipientEmail) {
            showToast(t('settings.recipient_required'), 'error');
            btn.disabled = false;
            btn.textContent = t('settings.test_email');
            return;
        }

        try {
            var formData = new FormData();
            formData.append('action', 'test-email');
            formData.append('emailConfig', JSON.stringify(emailData));
            formData.append('csrf_token', CSRF_TOKEN);

            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();

            resultEl.style.display = '';
            if (result.success) {
                resultEl.className = 'settings-test-result settings-test-result--success';
                resultEl.textContent = t('settings.test_email_success');
            } else {
                resultEl.className = 'settings-test-result settings-test-result--error';
                resultEl.textContent = result.message || t('settings.test_email_error');
            }
        } catch (error) {
            resultEl.style.display = '';
            resultEl.className = 'settings-test-result settings-test-result--error';
            resultEl.textContent = error.message;
        } finally {
            btn.disabled = false;
            btn.textContent = t('settings.test_email');
        }
    });
