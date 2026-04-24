<?php
// Footer Template
$basePath = $basePath ?? '';
$currentLang = $currentLang ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en');

// Check if admin is logged in
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isAdminLoggedIn = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$csrfToken = $_SESSION['csrf_token'] ?? '';

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
?>
    <!-- Footer -->
    <footer class="footer"<?php if ($isAdminLoggedIn): ?> data-content-page="footer"<?php endif; ?>>
        <div class="footer-accent"></div>
        <div class="footer-inner">
            <div class="footer-col footer-col--brand">
                <a href="<?php echo $basePath; ?>." class="footer-logo" aria-label="Home">
                    <?php
                    $_footerLogo = $_favicon ?? 'assets/images/favicon.svg';
                    ?>
                    <img class="site-logo-img" src="<?php echo $basePath . htmlspecialchars($_footerLogo); ?>" alt="" width="40" height="40">
                </a>
                <?php if ($creditText || $creditLinkText): ?>
                <p class="footer-credit"><span class="<?php echo $isAdminLoggedIn ? 'editable-footer-field' : ''; ?>" data-field="credit.text"><?php echo htmlspecialchars($creditText); ?></span><?php if ($creditLinkText): ?> <a href="<?php echo htmlspecialchars($creditLink); ?>" target="_blank" rel="noopener" class="<?php echo $isAdminLoggedIn ? 'editable-footer-field' : ''; ?>" data-field="credit.link" data-link-href="<?php echo htmlspecialchars($creditLink); ?>"><?php echo htmlspecialchars($creditLinkText); ?></a><?php endif; ?></p>
                <?php endif; ?>
            </div>

            <div class="footer-col footer-col--about">
                <?php if ($tagline): ?>
                <p class="footer-tagline<?php echo $isAdminLoggedIn ? ' editable-footer-field' : ''; ?>" data-field="tagline" data-lang="<?php echo $currentLang; ?>"><?php echo htmlspecialchars($tagline); ?></p>
                <?php endif; ?>
                <?php if ($services): ?>
                <p class="footer-services<?php echo $isAdminLoggedIn ? ' editable-footer-field' : ''; ?>" data-field="services" data-lang="<?php echo $currentLang; ?>"><?php echo htmlspecialchars($services); ?></p>
                <?php endif; ?>
                <?php if ($claim): ?>
                <p class="footer-claim<?php echo $isAdminLoggedIn ? ' editable-footer-field' : ''; ?>" data-field="claim" data-lang="<?php echo $currentLang; ?>"><?php echo htmlspecialchars($claim); ?></p>
                <?php endif; ?>
            </div>

            <?php
            // Footer nav columns — driven by menu registry (all menus except header)
            require_once __DIR__ . '/menu-helpers.php';
            $_footerMenuIds = array_filter(getRegisteredMenuIds(), fn($id) => $id !== 'header');
            $_footerAllNavItems = $NAV_ITEMS[$currentLang] ?? [];
            foreach ($_footerMenuIds as $_menuId):
                $_menuItems = getMenuItems($_menuId, $currentLang, $basePath, $_footerAllNavItems);
                // Flatten children for footer (no dropdowns)
                $_flat = [];
                foreach ($_menuItems as $_mi) {
                    if (!empty($_mi['children'])) {
                        foreach ($_mi['children'] as $_child) $_flat[] = $_child;
                    } else {
                        $_flat[] = $_mi;
                    }
                }
                $_menuItems = $_flat;
                if (!$_menuItems) continue;
            ?>
            <div class="footer-col footer-col--nav">
                <p class="footer-col-heading"><?php echo htmlspecialchars(getMenuLabel($_menuId, $currentLang)); ?></p>
                <nav class="meta-links">
                    <?php foreach ($_menuItems as $navItem): ?>
                    <a href="<?php echo $basePath . htmlspecialchars($navItem['href']); ?>"><?php echo htmlspecialchars($navItem['label']); ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="footer-bottom">
            <p class="footer-copyright<?php echo $isAdminLoggedIn ? ' editable-footer-field' : ''; ?>" data-field="copyright"><?php echo $copyrightHtml; ?></p>
        </div>
    </footer>

    <!-- Main JavaScript -->
    <script>
    (function() {
        'use strict';

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
                    hamburger.classList.remove('active');
                    hamburger.setAttribute('aria-expanded', 'false');
                    document.body.style.overflow = '';
                } else {
                    mobileNavOverlay.classList.add('active');
                    hamburger.classList.add('active');
                    hamburger.setAttribute('aria-expanded', 'true');
                    document.body.style.overflow = 'hidden';
                }
            });

            mobileNavOverlay.querySelectorAll('a').forEach(function(link) {
                link.addEventListener('click', function() {
                    mobileNavOverlay.classList.remove('active');
                    hamburger.classList.remove('active');
                    hamburger.setAttribute('aria-expanded', 'false');
                    document.body.style.overflow = '';
                });
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && mobileNavOverlay.classList.contains('active')) {
                    mobileNavOverlay.classList.remove('active');
                    hamburger.classList.remove('active');
                    hamburger.setAttribute('aria-expanded', 'false');
                    document.body.style.overflow = '';
                }
            });
        }

        // ============================================================
        // SMOOTH SCROLL FOR ANCHOR LINKS
        // ============================================================
        document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
            anchor.addEventListener('click', function(e) {
                const targetId = this.getAttribute('href');
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
            var STORAGE_KEY = 'site-theme';
            var CYCLE = ['dark', 'light'];

            function getStoredTheme() {
                try { return localStorage.getItem(STORAGE_KEY); } catch(e) { return null; }
            }

            function setTheme(theme) {
                document.documentElement.setAttribute('data-theme', theme);
                try { localStorage.setItem(STORAGE_KEY, theme); } catch(e) {}
                updateMobileButtons(theme);
            }

            function updateMobileButtons(theme) {
                document.querySelectorAll('.theme-toggle-mobile').forEach(function(btn) {
                    btn.classList.toggle('active', btn.dataset.themeChoice === theme);
                });
            }

            // Apply stored theme (migrate 'system' to 'dark')
            var stored = getStoredTheme();
            if (stored === 'system') stored = 'dark';
            if (stored && CYCLE.indexOf(stored) !== -1) {
                setTheme(stored);
            }

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

            // Initial state for mobile buttons
            var initial = document.documentElement.getAttribute('data-theme') || 'dark';
            updateMobileButtons(initial);
        })();

        // ============================================================
        // HIDDEN ADMIN ACCESS (double-click on year)
        // ============================================================
        const adminAccess = document.getElementById('adminAccess');
        if (adminAccess) {
            adminAccess.addEventListener('dblclick', function() {
                window.location.href = '<?php echo $basePath; ?>admin/';
            });
        }

        // ============================================================
        // CONTACT FORM AJAX SUBMISSION
        // ============================================================
        const contactForm = document.getElementById('contactForm');

        if (contactForm) {
            const submitBtn = document.getElementById('contactSubmit');
            const btnText = submitBtn.querySelector('.btn-text');
            const btnLoading = submitBtn.querySelector('.btn-loading');
            const feedback = document.getElementById('formFeedback');

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
                    } else {
                        feedback.className = 'form-feedback error';
                        feedback.textContent = data.message || feedback.dataset.error;
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
        }

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

    <?php if ($isAdminLoggedIn): ?>
    <!-- Inline Editor for logged-in admins -->
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <?php if (isset($contentPage)): ?>
    <meta name="content-page" content="<?php echo htmlspecialchars($contentPage); ?>">
    <?php endif; ?>
    <meta name="site-languages" content="<?php echo htmlspecialchars(json_encode($SITE_LANGUAGES ?? ['en' => 'English'])); ?>">
    <meta name="site-lang-default" content="<?php echo htmlspecialchars(defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'); ?>">
    <link rel="stylesheet" href="<?php echo $basePath; ?>css/nibbly-admin-tokens.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>css/image-manager.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>css/inline-editor.css">
    <?php if (!empty($_editorVars)): ?>
    <style>:root{<?php echo implode(';', $_editorVars); ?>}</style>
    <?php endif; ?>
    <script>
    window.BlockTypeRegistry = <?php echo json_encode(getBlockTypes(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); ?>;
    <?php
    // Inject editor translations for inline-editor.js
    require_once __DIR__ . '/../admin/lang/i18n.php';
    ?>
    window.NB_LANG = <?php echo json_encode(tEditorAll(), JSON_UNESCAPED_UNICODE); ?>;
    window.NB_MENUS = <?php echo json_encode(getMenuRegistry()['menus'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
    <?php
    // Build lightweight page list for link picker (slug → title for current language)
    $_linkPages = [];
    $_linkLang = $currentLang ?? (defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en');
    $_linkDefaultLang = defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en';
    foreach (glob(__DIR__ . '/../content/pages/' . $_linkLang . '_*.json') as $_pf) {
        $_slug = preg_replace('/^' . $_linkLang . '_/', '', basename($_pf, '.json'));
        $_pd = json_decode(file_get_contents($_pf), true);
        $_title = $_pd['title'] ?? ucfirst(str_replace('-', ' ', $_slug));
        $_href = $_slug === 'home' ? '/' : ($_linkLang === $_linkDefaultLang ? '/' . $_slug : '/' . $_linkLang . '/' . $_slug);
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
    <script src="<?php echo $basePath; ?>js/image-manager.js"></script>
    <script src="<?php echo $basePath; ?>js/inline-editor.js"></script>
    <?php endif; ?>
</body>
</html>
