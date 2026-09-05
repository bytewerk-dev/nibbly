<?php
// Footer Template
$basePath = $basePath ?? '';
$currentLang = $currentLang ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en');
$defaultLang = $defaultLang ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en');
$PAGE_MAPPING = $PAGE_MAPPING ?? [];
$langLinks = $langLinks ?? [];
require_once __DIR__ . '/page-path.php';

// Check if admin is logged in
require_once __DIR__ . '/access-guard.php';
$isAdminLoggedIn = nibblyAccessIsLoggedIn();
$csrfToken = $isAdminLoggedIn ? ($_SESSION['csrf_token'] ?? '') : '';

if ($isAdminLoggedIn && !function_exists('getBlockTypes')) {
    require_once __DIR__ . '/content-loader.php';
}

// Load footer content from JSON
$footerData = [];
$footerJsonPath = __DIR__ . '/../content/pages/footer.json';
if (file_exists($footerJsonPath)) {
    $footerData = json_decode(file_get_contents($footerJsonPath), true) ?: [];
}

// Language-dependent texts
$tagline = $footerData['tagline'][$currentLang] ?? '';
$services = $footerData['services'][$currentLang] ?? '';
$claim = $footerData['claim'][$currentLang] ?? '';
$phone = $footerData['contact']['phone'] ?? '';
$email = $footerData['contact']['email'] ?? 'info@example.com';
$creditText = $footerData['credit']['text'] ?? '';
$creditLink = $footerData['credit']['link'] ?? '';
$creditLinkText = $footerData['credit']['linkText'] ?? '';
$contactHeading = $footerData['contactHeading'][$currentLang] ?? 'Contact';
$siteName = $_settings['branding']['name'] ?? (defined('SITE_NAME') ? SITE_NAME : '');

$copyrightVal = $footerData['copyright'] ?? '&copy; [id="adminAccess"]' . date('Y') . '[/id]';
$copyrightRaw = is_array($copyrightVal) ? ($copyrightVal[$currentLang] ?? $copyrightVal[array_key_first($copyrightVal)] ?? '') : $copyrightVal;

/**
 * Parse shortcode-like syntax: [id="foo"]content[/id] → <span id="foo">content</span>
 * Supports: [id="..."], [class="..."], combined [id="..." class="..."]
 */
function parseFooterShortcodes($text) {
    // [id="value" class="value"]content[/id] or [/class]
    return preg_replace_callback(
        '/\[([^\]]+)\](.*?)\[\/\w+\]/s',
        function ($m) {
            $attrs = $m[1];
            $content = $m[2];
            $htmlAttrs = '';
            if (preg_match('/id="([^"]*)"/', $attrs, $id)) {
                $htmlAttrs .= ' id="' . htmlspecialchars($id[1]) . '"';
            }
            if (preg_match('/class="([^"]*)"/', $attrs, $cls)) {
                $htmlAttrs .= ' class="' . htmlspecialchars($cls[1]) . '"';
            }
            return '<span' . $htmlAttrs . '>' . $content . '</span>';
        },
        $text
    );
}

$copyrightHtml = parseFooterShortcodes($copyrightRaw);
$_footerGithub = 'https://github.com/bytewerk-dev/nibbly';
$_adminAccessBase = ($basePath === '' ? '/admin/' : rtrim($basePath, '/') . '/admin/');
$_adminAccessBaseJson = json_encode($_adminAccessBase, JSON_UNESCAPED_SLASHES);
$_footerLabels = [
    'docs' => ['en' => 'Docs', 'de' => 'Dokumentation', 'es' => 'Docs'],
    'privacy' => ['en' => 'Privacy', 'de' => 'Datenschutz', 'es' => 'Privacidad'],
    'imprint' => ['en' => 'Imprint', 'de' => 'Impressum', 'es' => 'Aviso legal'],
    'language' => ['en' => 'Language selection', 'de' => 'Sprachauswahl', 'es' => 'Selección de idioma'],
];
$_footerPageHref = function (string $page) use ($basePath, $currentLang, $PAGE_MAPPING, $defaultLang): string {
    if (isset($PAGE_MAPPING[$page][$currentLang])) {
        return $basePath . $PAGE_MAPPING[$page][$currentLang];
    }
    if (isset($PAGE_MAPPING[$page][$defaultLang])) {
        return $basePath . $PAGE_MAPPING[$page][$defaultLang];
    }
    return $basePath . $page;
};
$_footerSummary = [
    'en' => 'nibbly is a flat-file CMS written in PHP.<br>Small. Friendly. Open source.',
    'de' => 'nibbly ist ein Flat-File-CMS in PHP.<br>Klein. Freundlich. Open Source.',
    'es' => 'nibbly es un CMS flat-file escrito en PHP.<br>Pequeño. Amable. Open source.',
];
?>
    <!-- Footer -->
    <footer class="footer"<?php if ($isAdminLoggedIn): ?> data-content-page="footer"<?php endif; ?>>
        <div class="footer-accent"></div>
        <div class="footer-inner footer-product-bar">
            <div class="footer-brand-block">
                <a href="<?php echo $basePath; ?>." class="footer-wordmark" aria-label="Home"><?php echo htmlspecialchars($siteName ?: 'nibbly'); ?></a>
                <p class="footer-copyright<?php echo $isAdminLoggedIn ? ' editable-footer-field' : ''; ?>"<?php if ($isAdminLoggedIn): ?> data-field="copyright"<?php endif; ?>><?php echo $copyrightHtml; ?></p>
                <p class="footer-credit">
                    by <a href="https://bytewerk.dev" target="_blank" rel="noopener">bytewerk</a>
                </p>
            </div>

            <div class="footer-summary">
                <p><?php echo $_footerSummary[$currentLang] ?? $_footerSummary['en']; ?></p>
            </div>

            <div class="footer-actions">
                <nav class="footer-links" aria-label="Footer">
                    <a href="<?php echo htmlspecialchars($_footerPageHref('docs')); ?>"><?php echo htmlspecialchars($_footerLabels['docs'][$currentLang] ?? $_footerLabels['docs']['en']); ?></a>
                    <a href="<?php echo htmlspecialchars($_footerGithub); ?>" target="_blank" rel="noopener">GitHub</a>
                    <a href="<?php echo htmlspecialchars($_footerPageHref('privacy')); ?>"><?php echo htmlspecialchars($_footerLabels['privacy'][$currentLang] ?? $_footerLabels['privacy']['en']); ?></a>
                    <a href="<?php echo htmlspecialchars($_footerPageHref('legal-notice')); ?>"><?php echo htmlspecialchars($_footerLabels['imprint'][$currentLang] ?? $_footerLabels['imprint']['en']); ?></a>
                </nav>

                <?php if (!empty($langLinks) && count($langLinks) > 1): ?>
                <div class="footer-lang" aria-label="<?php echo htmlspecialchars($_footerLabels['language'][$currentLang] ?? $_footerLabels['language']['en']); ?>">
                    <span class="footer-lang-globe" aria-hidden="true"></span>
                    <?php $footerLangCodes = array_keys($langLinks); ?>
                    <?php foreach ($footerLangCodes as $i => $code): ?>
                        <?php if ($i > 0): ?><span class="footer-lang-separator">|</span><?php endif; ?>
                        <a href="<?php echo htmlspecialchars($langLinks[$code]); ?>" class="<?php echo ($code === $currentLang) ? 'active' : ''; ?>"<?php echo ($code === $currentLang) ? ' aria-current="true"' : ''; ?>><?php echo htmlspecialchars(strtoupper($code)); ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </footer>

    <!-- Main JavaScript -->
    <script>
    (function() {
        'use strict';

        const mainEl = document.querySelector('main');
        if (mainEl && !mainEl.id) {
            mainEl.id = 'main-content';
        }

        // ============================================================
        // HEADER SCROLL BEHAVIOR
        // ============================================================
        const header = document.getElementById('siteHeader');
        let lastScrollY = 0;
        let ticking = false;
        const scrollThreshold = 150;

        function updateHeader() {
            const currentScrollY = window.scrollY;

            if (currentScrollY > 50) {
                header.classList.add('header-scrolled');
            } else {
                header.classList.remove('header-scrolled');
            }

            if (currentScrollY > scrollThreshold) {
                if (currentScrollY > lastScrollY) {
                    header.classList.add('header-hidden');
                } else {
                    header.classList.remove('header-hidden');
                }
            } else {
                header.classList.remove('header-hidden');
            }

            lastScrollY = currentScrollY;
            ticking = false;
        }

        window.addEventListener('scroll', function() {
            if (!ticking) {
                window.requestAnimationFrame(updateHeader);
                ticking = true;
            }
        }, { passive: true });

        // ============================================================
        // MOBILE NAVIGATION
        // ============================================================
        const hamburger = document.getElementById('hamburger');
        const mobileNavOverlay = document.getElementById('mobileNavOverlay');

        if (hamburger && mobileNavOverlay) {
            hamburger.addEventListener('click', function() {
                const isOpen = mobileNavOverlay.classList.contains('active');

                if (isOpen) {
                    mobileNavOverlay.classList.remove('active');
                    mobileNavOverlay.setAttribute('aria-hidden', 'true');
                    hamburger.classList.remove('active');
                    hamburger.setAttribute('aria-expanded', 'false');
                    hamburger.setAttribute('aria-label', 'Open menu');
                    document.body.style.overflow = '';
                } else {
                    mobileNavOverlay.classList.add('active');
                    mobileNavOverlay.setAttribute('aria-hidden', 'false');
                    hamburger.classList.add('active');
                    hamburger.setAttribute('aria-expanded', 'true');
                    hamburger.setAttribute('aria-label', 'Close menu');
                    document.body.style.overflow = 'hidden';
                    var firstMobileLink = mobileNavOverlay.querySelector('a, button');
                    if (firstMobileLink) firstMobileLink.focus();
                }
            });

            mobileNavOverlay.querySelectorAll('a').forEach(function(link) {
                link.addEventListener('click', function() {
                    mobileNavOverlay.classList.remove('active');
                    mobileNavOverlay.setAttribute('aria-hidden', 'true');
                    hamburger.classList.remove('active');
                    hamburger.setAttribute('aria-expanded', 'false');
                    hamburger.setAttribute('aria-label', 'Open menu');
                    document.body.style.overflow = '';
                });
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && mobileNavOverlay.classList.contains('active')) {
                    mobileNavOverlay.classList.remove('active');
                    mobileNavOverlay.setAttribute('aria-hidden', 'true');
                    hamburger.classList.remove('active');
                    hamburger.setAttribute('aria-expanded', 'false');
                    hamburger.setAttribute('aria-label', 'Open menu');
                    document.body.style.overflow = '';
                    hamburger.focus();
                }
            });
        }

        // ============================================================
        // SMOOTH SCROLL FOR ANCHOR LINKS
        // ============================================================
        document.querySelectorAll('a[href^="#"], a[data-nav-hash]').forEach(function(anchor) {
            anchor.addEventListener('click', function(e) {
                var targetId = this.getAttribute('href');
                if (this.dataset.navHash) {
                    try {
                        var url = new URL(this.href, window.location.href);
                        if (url.pathname !== window.location.pathname) return;
                        targetId = '#' + this.dataset.navHash;
                    } catch(e) {}
                }
                if (targetId === '#') return;

                const targetElement = document.querySelector(targetId);
                if (targetElement) {
                    e.preventDefault();
                    const headerHeight = header ? header.offsetHeight : 0;
                    const targetPosition = targetElement.getBoundingClientRect().top + window.scrollY - headerHeight;

                    window.scrollTo({
                        top: targetPosition,
                        behavior: 'smooth'
                    });
                }
            });
        });

        // One-page menu active state: hash links become active only while
        // their target section is actually visible on the current page.
        (function initOnePageNavActiveState() {
            var links = Array.prototype.slice.call(document.querySelectorAll('a[data-nav-hash]'));
            if (!links.length || !('IntersectionObserver' in window)) return;

            var sectionMap = new Map();
            links.forEach(function(link) {
                var id = link.dataset.navHash;
                if (!id || sectionMap.has(id)) return;
                var section = document.getElementById(id);
                if (section) sectionMap.set(id, section);
            });
            if (!sectionMap.size) return;

            function setActive(id) {
                links.forEach(function(link) {
                    link.classList.toggle('active', link.dataset.navHash === id);
                });
            }

            var visible = new Map();
            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    var id = entry.target.id;
                    if (entry.isIntersecting) {
                        visible.set(id, entry.intersectionRatio);
                    } else {
                        visible.delete(id);
                    }
                });
                var best = null;
                var bestRatio = 0;
                visible.forEach(function(ratio, id) {
                    if (ratio > bestRatio) {
                        best = id;
                        bestRatio = ratio;
                    }
                });
                if (best) setActive(best);
            }, {
                rootMargin: '-25% 0px -55% 0px',
                threshold: [0.1, 0.25, 0.5, 0.75]
            });

            sectionMap.forEach(function(section) {
                observer.observe(section);
            });
        })();

        // ============================================================
        // SCROLL REVEAL ANIMATIONS
        // ============================================================
        (function initRevealAnimations() {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                document.querySelectorAll('.reveal').forEach(function(el) {
                    el.classList.remove('reveal');
                });
                document.querySelectorAll('.stagger-reveal').forEach(function(el) {
                    el.classList.remove('stagger-reveal');
                });
                return;
            }

            var revealElements = document.querySelectorAll('.reveal');
            if (revealElements.length) {
                var observer = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('revealed');
                            observer.unobserve(entry.target);
                        }
                    });
                }, {
                    threshold: 0.15,
                    rootMargin: '0px 0px -40px 0px'
                });

                revealElements.forEach(function(el) {
                    observer.observe(el);
                });
            }

            function staggerChildren(container, baseDelay) {
                var children = container.children;
                for (var i = 0; i < children.length; i++) {
                    (function(child, delay) {
                        child.style.animationDelay = delay + 'ms';
                        setTimeout(function() {
                            child.classList.add('stagger-visible');
                        }, 10);
                    })(children[i], baseDelay + (i * 120));
                }
            }

            var staggerContainers = document.querySelectorAll('.stagger-reveal');
            if (staggerContainers.length) {
                staggerContainers.forEach(function(el) {
                    var revealParent = el.closest('.reveal');
                    if (revealParent) {
                        var mo = new MutationObserver(function(mutations) {
                            mutations.forEach(function(m) {
                                if (m.target.classList.contains('revealed')) {
                                    setTimeout(function() {
                                        staggerChildren(el, 100);
                                    }, 500);
                                    mo.disconnect();
                                }
                            });
                        });
                        mo.observe(revealParent, { attributes: true, attributeFilter: ['class'] });
                    } else {
                        var staggerObserver = new IntersectionObserver(function(entries) {
                            entries.forEach(function(entry) {
                                if (entry.isIntersecting) {
                                    staggerChildren(entry.target, 150);
                                    staggerObserver.unobserve(entry.target);
                                }
                            });
                        }, {
                            threshold: 0.05,
                            rootMargin: '0px 0px -20px 0px'
                        });
                        staggerObserver.observe(el);
                    }
                });
            }
        })();

        // ============================================================
        // THEME TOGGLE (Dark / Light)
        // ============================================================
        (function initThemeToggle() {
            var REMEMBER_PUBLIC_THEME = <?php echo (!isset($_settings['privacy']['rememberPublicTheme']) || !empty($_settings['privacy']['rememberPublicTheme'])) ? 'true' : 'false'; ?>;
            var PUBLIC_THEME_DEFAULT = <?php
                $_footerPublicThemeDefault = $_settings['theme']['publicDefault'] ?? 'system';
                if (!in_array($_footerPublicThemeDefault, ['light', 'dark', 'system'], true)) {
                    $_footerPublicThemeDefault = 'system';
                }
                echo json_encode($_footerPublicThemeDefault);
            ?>;
            var STORAGE_KEY = 'site-theme';
            var CYCLE = ['dark', 'light'];
            var THEME_FAVICON_COLORS = { light: '#0a0a0a', dark: '#e5e5e5' };
            var faviconSvgCache = null;

            function getSystemTheme() {
                return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }

            function getDefaultTheme() {
                return CYCLE.indexOf(PUBLIC_THEME_DEFAULT) !== -1 ? PUBLIC_THEME_DEFAULT : getSystemTheme();
            }

            function getInitialTheme() {
                if (!REMEMBER_PUBLIC_THEME) {
                    return getDefaultTheme();
                }
                try {
                    var stored = localStorage.getItem(STORAGE_KEY);
                    if (stored === 'system') stored = null;
                    if (CYCLE.indexOf(stored) !== -1) {
                        return stored;
                    }
                } catch(e) {}
                return getDefaultTheme();
            }

            function updateBrowserFavicon(theme) {
                var link = document.querySelector('link[rel="icon"]');
                if (!link) return;
                var href = link.getAttribute('data-original-href') || link.getAttribute('href');
                if (!href || !/\.svg(\?|#|$)/i.test(href)) return;
                if (!link.getAttribute('data-original-href')) link.setAttribute('data-original-href', href);
                if (faviconSvgCache === null) {
                    fetch(href).then(function(r){ return r.ok ? r.text() : null; }).then(function(svg){
                        if (!svg) return;
                        faviconSvgCache = svg;
                        applyFavicon(theme);
                    }).catch(function(){});
                } else {
                    applyFavicon(theme);
                }
            }

            function applyFavicon(theme) {
                if (!faviconSvgCache) return;
                var color = THEME_FAVICON_COLORS[theme] || THEME_FAVICON_COLORS.light;
                var patched = faviconSvgCache
                    .replace(/<svg\b/, '<svg data-theme="' + theme + '"')
                    .replace(/currentColor/g, color);
                var dataUrl = 'data:image/svg+xml;utf8,' + encodeURIComponent(patched);
                document.querySelectorAll('link[rel="icon"], link[rel="alternate icon"]').forEach(function(l){
                    if (/\.svg(\?|#|$)/i.test(l.getAttribute('data-original-href') || l.getAttribute('href') || '')) {
                        l.setAttribute('href', dataUrl);
                    }
                });
            }

            function setTheme(theme) {
                document.documentElement.setAttribute('data-theme', theme);
                if (REMEMBER_PUBLIC_THEME) {
                    try { localStorage.setItem(STORAGE_KEY, theme); } catch(e) {}
                }
                updateMobileButtons(theme);
                updateBrowserFavicon(theme);
            }

            function updateMobileButtons(theme) {
                document.querySelectorAll('.theme-toggle-mobile').forEach(function(btn) {
                    btn.classList.toggle('active', btn.dataset.themeChoice === theme);
                });
            }

            setTheme(getInitialTheme());

            // Desktop toggle: cycles dark → light → dark
            var desktopToggle = document.getElementById('themeToggle');
            if (desktopToggle) {
                desktopToggle.addEventListener('click', function() {
                    var current = document.documentElement.getAttribute('data-theme') || 'dark';
                    var next = current === 'dark' ? 'light' : 'dark';
                    setTheme(next);
                });
            }

            // Mobile toggles: direct selection
            document.querySelectorAll('.theme-toggle-mobile').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    setTheme(btn.dataset.themeChoice);
                });
            });

            // Initial state for mobile buttons + favicon
            var initial = document.documentElement.getAttribute('data-theme') || 'dark';
            updateMobileButtons(initial);
            updateBrowserFavicon(initial);
        })();

        // ============================================================
        // HIDDEN ADMIN ACCESS (double-click on year)
        // ============================================================
        let lastAdminAccessClick = 0;
        const openAdminAccess = function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
            const adminUrl = new URL(<?php echo $_adminAccessBaseJson; ?>, window.location.origin);
            adminUrl.searchParams.set('redirect', window.location.pathname + window.location.search + window.location.hash);
            window.location.assign(adminUrl.toString());
        };
        document.addEventListener('click', function(e) {
            const adminAccess = e.target && e.target.closest ? e.target.closest('#adminAccess') : null;
            if (!adminAccess) return;
            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
            const now = Date.now();
            if (e.detail >= 2 || now - lastAdminAccessClick < 700) {
                openAdminAccess(e);
                return;
            }
            lastAdminAccessClick = now;
        }, true);
        document.addEventListener('dblclick', function(e) {
            const adminAccess = e.target && e.target.closest ? e.target.closest('#adminAccess') : null;
            if (adminAccess) openAdminAccess(e);
        }, true);

        // ============================================================
        // CONTACT FORM AJAX SUBMISSION
        // ============================================================
        const bindContactForm = function(contactForm) {
            if (!contactForm || contactForm.dataset.nibblyContactBound === 'true') return;

            const submitBtn = contactForm.querySelector('[type="submit"]');
            if (!submitBtn) return;

            const btnText = submitBtn.querySelector('.btn-text');
            const btnLoading = submitBtn.querySelector('.btn-loading');
            const feedback = contactForm.querySelector('.form-feedback');
            if (!btnText || !btnLoading || !feedback) return;
            contactForm.dataset.nibblyContactBound = 'true';

            const updateFormToken = function(token) {
                const tokenField = contactForm.querySelector('input[name="form_token"]');
                if (!tokenField || !token) return;
                tokenField.value = token;
                tokenField.defaultValue = token;
            };

            contactForm.addEventListener('submit', function(e) {
                e.preventDefault();

                btnText.style.display = 'none';
                btnLoading.style.display = 'inline';
                submitBtn.disabled = true;
                feedback.className = 'form-feedback';
                feedback.textContent = '';

                const formData = new FormData(contactForm);

                fetch(contactForm.action, {
                    method: 'POST',
                    body: formData
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    if (data.success) {
                        feedback.className = 'form-feedback success';
                        feedback.textContent = feedback.dataset.success;
                        contactForm.reset();
                        updateFormToken(data.formToken);
                    } else {
                        feedback.className = 'form-feedback error';
                        feedback.textContent = data.message || feedback.dataset.error;
                        updateFormToken(data.formToken);
                    }
                })
                .catch(function() {
                    feedback.className = 'form-feedback error';
                    feedback.textContent = feedback.dataset.error;
                })
                .finally(function() {
                    btnText.style.display = 'inline';
                    btnLoading.style.display = 'none';
                    submitBtn.disabled = false;
                });
            });
        };

        const runInsertedScripts = function(container) {
            container.querySelectorAll('script').forEach(function(oldScript) {
                const script = document.createElement('script');
                Array.prototype.forEach.call(oldScript.attributes, function(attr) {
                    script.setAttribute(attr.name, attr.value);
                });
                script.textContent = oldScript.textContent;
                oldScript.replaceWith(script);
            });
        };

        const loadLazyForm = function(container) {
            if (!container || container.dataset.loaded === 'true' || container.dataset.loading === 'true') return;

            container.dataset.loading = 'true';
            const params = new URLSearchParams();
            params.set('form', container.dataset.nibblyLazyForm || '');

            Array.prototype.forEach.call(container.attributes, function(attr) {
                if (attr.name.indexOf('data-param-') !== 0) return;
                params.set(attr.name.replace('data-param-', ''), attr.value);
            });

            fetch((container.dataset.endpoint || 'api/form.php') + '?' + params.toString(), {
                method: 'GET',
                credentials: 'same-origin'
            })
            .then(function(response) {
                if (!response.ok) throw new Error('Form request failed');
                return response.text();
            })
            .then(function(html) {
                container.innerHTML = html;
                runInsertedScripts(container);
                container.querySelectorAll('form.contact-form').forEach(bindContactForm);
                container.dataset.loaded = 'true';
                container.dispatchEvent(new CustomEvent('nibbly:lazy-form-loaded', { bubbles: true }));
            })
            .catch(function() {
                container.innerHTML = '<p class="form-feedback error">This form could not be loaded. Please try again later.</p>';
            })
            .finally(function() {
                delete container.dataset.loading;
            });
        };

        document.querySelectorAll('[data-nibbly-lazy-form]').forEach(function(container) {
            window.setTimeout(function() {
                loadLazyForm(container);
            }, parseInt(container.dataset.delay || '3500', 10));
        });

        document.querySelectorAll('form.contact-form').forEach(bindContactForm);

    })();
    </script>

    <?php if (!empty($pageExternalScripts)): ?>
    <?php foreach ($pageExternalScripts as $_extScript): ?>
    <script src="<?php echo htmlspecialchars($_extScript); ?>"></script>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Custom Audio Player -->
    <script src="<?php echo $basePath; ?>js/audio-player.js"></script>

    <?php if (isset($pageClass) && strpos($pageClass, 'page-landing') !== false && file_exists(__DIR__ . '/../js/landing-effects.js')): ?>
    <script src="<?php echo $basePath; ?>js/landing-effects.js"></script>
    <?php endif; ?>

    <?php if (file_exists(__DIR__ . '/../js/faq-accordion.js')): ?>
    <script src="<?php echo $basePath; ?>js/faq-accordion.js"></script>
    <?php endif; ?>

    <?php if (!$isAdminLoggedIn && !empty($_settings['privacy']['emailObfuscation']) && file_exists(__DIR__ . '/../js/email-obfuscator.js')): ?>
    <script src="<?php echo $basePath; ?>js/email-obfuscator.js"></script>
    <?php endif; ?>

    <?php if ($isAdminLoggedIn): ?>
    <!-- Inline Editor for logged-in admins -->
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <?php if (isset($contentPage)): ?>
    <meta name="content-page" content="<?php echo htmlspecialchars($contentPage); ?>">
    <?php endif; ?>
    <meta name="site-languages" content="<?php echo htmlspecialchars(json_encode($SITE_LANGUAGES ?? ['en' => 'English'])); ?>">
    <meta name="site-lang-default" content="<?php echo htmlspecialchars(defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'); ?>">
    <?php
    // Cache-busting for editor assets: query string with file mtime forces
    // a fresh fetch as soon as the file changes on the server. Without
    // this, browsers serve the previous JS/CSS from disk cache and admins
    // continue to see old bugs after a hotfix.
    $_assetsDir = __DIR__ . '/..';
    $_v = function($relPath) use ($basePath, $_assetsDir) {
        $full = $_assetsDir . '/' . ltrim($relPath, '/');
        clearstatcache(true, $full);
        $mtime = is_file($full) ? filemtime($full) : 0;
        return $basePath . $relPath . ($mtime ? '?v=' . $mtime : '');
    };
    $_footerModules = is_array($_settings['modules'] ?? null) ? $_settings['modules'] : [];
    $_aiModuleEnabled = !array_key_exists('ai', $_footerModules) || !empty($_footerModules['ai']);
    $_aiCopilotAvailable = false;
    if ($_aiModuleEnabled && is_file(__DIR__ . '/ai/ai-helper.php')) {
        require_once __DIR__ . '/ai/ai-helper.php';
        $_aiPublicSettings = function_exists('nibblyAiLoadSettings') ? nibblyAiLoadSettings(true) : [];
        $_aiCopilotAvailable = !empty($_aiPublicSettings['enabled'])
            && !empty($_aiPublicSettings['hasApiKey'])
            && !empty($_aiPublicSettings['features']['backendAssistant'])
            && (!isset($_aiPublicSettings['assistantSurfaces']['visualEditor']) || !empty($_aiPublicSettings['assistantSurfaces']['visualEditor']));
    }
    ?>
    <link rel="stylesheet" href="<?php echo $basePath; ?>css/nibbly-admin-tokens.css">
    <link rel="stylesheet" href="<?php echo $_v('css/image-manager.css'); ?>">
    <link rel="stylesheet" href="<?php echo $_v('css/inline-editor.css'); ?>">
    <link rel="stylesheet" href="<?php echo $_v('css/nb-select.css'); ?>">
    <?php if ($_aiCopilotAvailable && file_exists(__DIR__ . '/../css/ai-copilot.css')): ?>
    <link rel="stylesheet" href="<?php echo $_v('css/ai-copilot.css'); ?>">
    <?php endif; ?>
    <?php if (!empty($_editorVars)): ?>
    <style>:root{<?php echo implode(';', $_editorVars); ?>}</style>
    <?php endif; ?>
    <script>
    window.BlockTypeRegistry = <?php echo json_encode(getBlockTypes(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); ?>;
    <?php
    // Inject editor translations for inline-editor.js
    require_once __DIR__ . '/../admin/lang/i18n.php';
    ?>
    window.NB_LANG = <?php echo json_encode(array_merge(tAll(), tEditorAll()), JSON_UNESCAPED_UNICODE); ?>;
    window.NB_MENUS = <?php echo json_encode(getMenuRegistry()['menus'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
    window.NB_AVAILABLE_ICONS = <?php echo json_encode(function_exists('getAvailableIcons') ? getAvailableIcons() : [], JSON_UNESCAPED_UNICODE); ?>;
    window.NB_SEO_HEALTH = <?php echo json_encode($_seoHealth ?? ['status' => 'yellow', 'score' => 0, 'label' => 'SEO prüfen', 'issues' => ['SEO-Daten konnten nicht berechnet werden.']], JSON_UNESCAPED_UNICODE); ?>;
    window.NB_AI_FEATURES_ENABLED = <?php echo json_encode($_aiModuleEnabled); ?>;
    window.NB_AI_COPILOT_AVAILABLE = <?php echo json_encode($_aiCopilotAvailable); ?>;
    window.NB_AI_ASSISTANT_LANGUAGE = <?php echo json_encode(function_exists('_nbAdminLang') ? _nbAdminLang() : ($currentLang ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'))); ?>;
    window.NB_ADMIN_API_URL = <?php echo json_encode($basePath . 'admin/api.php', JSON_UNESCAPED_SLASHES); ?>;
    window.NB_ADMIN_BASE_URL = <?php echo $_adminAccessBaseJson; ?>;
    <?php
    // Build lightweight page list for link picker (slug → title for current language)
    $_linkPages = [];
    $_linkLang = $currentLang ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en');
    $_linkDefaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    foreach (glob(__DIR__ . '/../content/pages/' . $_linkLang . '_*.json') as $_pf) {
        $_page = nibblyPageParseContentKey(basename($_pf, '.json'));
        if ($_page === null || $_page['lang'] !== $_linkLang) continue;
        $_slug = $_page['path'];
        $_pd = json_decode(file_get_contents($_pf), true);
        $_title = $_pd['title'] ?? ucfirst(str_replace('-', ' ', $_slug));
        $_href = nibblyPageUrlPath($_linkLang, $_slug, $_linkDefaultLang);
        $_linkPages[] = ['slug' => $_slug, 'title' => $_title, 'href' => $_href];
    }
    usort($_linkPages, function($a, $b) { return strcasecmp($a['title'], $b['title']); });
    ?>
    window.NB_PAGES = <?php echo json_encode($_linkPages, JSON_UNESCAPED_UNICODE); ?>;
    <?php
    // Surface auto-generated fields so the editor can show a toast
    $autoGenFields = function_exists('autoGeneratedFields') ? autoGeneratedFields() : [];
    if ($autoGenFields):
    ?>
    window.NB_AUTO_GENERATED = <?php echo json_encode($autoGenFields, JSON_UNESCAPED_UNICODE); ?>;
    <?php endif; ?>
    function t(key, params) {
        let s = (window.NB_LANG && window.NB_LANG[key]) || key;
        if (params) { for (const [k, v] of Object.entries(params)) { s = s.replace('{' + k + '}', v); } }
        return s;
    }
    </script>
    <script src="<?php echo $_v('js/nb-select.js'); ?>"></script>
    <script src="<?php echo $_v('js/image-manager.js'); ?>"></script>
    <link rel="stylesheet" href="<?php echo $_v('css/revision-client.css'); ?>">
    <script src="<?php echo $_v('js/revision-client.js'); ?>"></script>
    <script src="<?php echo $_v('js/inline-editor.js'); ?>"></script>
    <?php if ($_aiCopilotAvailable && file_exists(__DIR__ . '/../js/ai-copilot.js')): ?>
    <script src="<?php echo $_v('js/ai-copilot.js'); ?>"></script>
    <?php endif; ?>
    <?php endif; ?>
</body>
</html>
