/**
 * Landing Page Effects
 * Parallax, animated counters, header transparency, step connector animation.
 * Only active on .page-landing pages. Respects prefers-reduced-motion.
 */
(function () {
    'use strict';

    // Skip everything if not on the landing page
    if (!document.body.classList.contains('page-landing')) return;

    // Respect reduced motion preference
    var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ============================================================
    // 1. HEADER TRANSPARENCY
    // ============================================================
    var header = document.getElementById('siteHeader');
    var heroSection = document.querySelector('.landing-hero');

    if (header && heroSection) {
        // Start transparent
        header.classList.add('header-transparent');

        var heroObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    header.classList.add('header-transparent');
                } else {
                    header.classList.remove('header-transparent');
                }
            });
        }, {
            threshold: 0,
            rootMargin: '-80px 0px 0px 0px'
        });

        heroObserver.observe(heroSection);
    }

    // ============================================================
    // 2. PARALLAX SCROLLING
    // ============================================================
    if (!prefersReducedMotion) {
        var parallaxElements = document.querySelectorAll('[data-parallax]');

        if (parallaxElements.length) {
            var ticking = false;

            function updateParallax() {
                var scrollY = window.scrollY;
                parallaxElements.forEach(function (el) {
                    var speed = parseFloat(el.dataset.parallax) || 0.05;
                    var rect = el.getBoundingClientRect();
                    var offset = (scrollY - el.offsetTop + window.innerHeight) * speed;
                    el.style.transform = 'translateY(' + offset + 'px)';
                });
                ticking = false;
            }

            window.addEventListener('scroll', function () {
                if (!ticking) {
                    window.requestAnimationFrame(updateParallax);
                    ticking = true;
                }
            }, { passive: true });
        }
    }

    // ============================================================
    // 3. ANIMATED COUNTERS
    // ============================================================
    if (!prefersReducedMotion) {
        var counterElements = document.querySelectorAll('[data-count-to]');

        if (counterElements.length) {
            var counterObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        animateCounter(entry.target);
                        counterObserver.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.5
            });

            counterElements.forEach(function (el) {
                counterObserver.observe(el);
            });
        }
    }

    function animateCounter(el) {
        var target = el.dataset.countTo;
        var prefix = '';
        var suffix = '';
        var numericTarget = 0;

        // Extract numeric value and any prefix/suffix (e.g. "<500KB", "100%", "∞")
        var match = target.match(/^([^0-9]*)(\d+)(.*)$/);
        if (!match) {
            // Non-numeric values like "∞" — just show immediately
            return;
        }

        prefix = match[1];
        numericTarget = parseInt(match[2], 10);
        suffix = match[3];

        var duration = 1500;
        var startTime = null;

        function easeOutExpo(t) {
            return t === 1 ? 1 : 1 - Math.pow(2, -10 * t);
        }

        function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var easedProgress = easeOutExpo(progress);
            var currentValue = Math.round(numericTarget * easedProgress);

            el.textContent = prefix + currentValue + suffix;

            if (progress < 1) {
                requestAnimationFrame(step);
            }
        }

        requestAnimationFrame(step);
    }

    // ============================================================
    // 4. VIBECODING ACCORDION
    // ============================================================
    var accordionCards = document.querySelectorAll('.vibecoding-card');

    if (accordionCards.length) {
        accordionCards.forEach(function (card) {
            var trigger = card.querySelector('.vibecoding-card-trigger');
            if (!trigger) return;

            trigger.addEventListener('click', function () {
                // Skip accordion toggle in edit mode — all cards are shown expanded
                if (document.body.classList.contains('edit-mode-active')) return;
                var isActive = card.classList.contains('vibecoding-card-active');

                // Close all
                accordionCards.forEach(function (c) {
                    c.classList.remove('vibecoding-card-active');
                    var t = c.querySelector('.vibecoding-card-trigger');
                    if (t) t.setAttribute('aria-expanded', 'false');
                });

                // Open clicked (unless it was already open)
                if (!isActive) {
                    card.classList.add('vibecoding-card-active');
                    trigger.setAttribute('aria-expanded', 'true');
                }
            });
        });

        // Auto-cycle when section is sufficiently visible in the viewport.
        // Stops when the section is less than 50% visible to prevent layout
        // shifts from off-screen accordion transitions.
        var autoInterval = null;
        var userInteracted = false;
        var sectionVisible = false;

        function startAutoCycle() {
            if (autoInterval || prefersReducedMotion) return;
            autoInterval = setInterval(function () {
                if (userInteracted || !sectionVisible) return;
                if (document.body.classList.contains('edit-mode-active')) return;
                var activeCard = document.querySelector('.vibecoding-card-active');
                var cards = Array.from(accordionCards);
                var idx = activeCard ? cards.indexOf(activeCard) : -1;
                var next = (idx + 1) % cards.length;

                cards.forEach(function (c) {
                    c.classList.remove('vibecoding-card-active');
                    var t = c.querySelector('.vibecoding-card-trigger');
                    if (t) t.setAttribute('aria-expanded', 'false');
                });

                cards[next].classList.add('vibecoding-card-active');
                var nextTrigger = cards[next].querySelector('.vibecoding-card-trigger');
                if (nextTrigger) nextTrigger.setAttribute('aria-expanded', 'true');
            }, 4000);
        }

        // Observe visibility: start cycling when ≥50% visible, pause when not
        var accordionSection = document.querySelector('.landing-vibecoding');
        if (accordionSection) {
            var accObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    sectionVisible = entry.isIntersecting;
                    if (sectionVisible && !userInteracted) {
                        startAutoCycle();
                    }
                });
            }, { threshold: 0.5 });
            accObserver.observe(accordionSection);
        }

        // Stop auto-cycle permanently on user interaction
        accordionCards.forEach(function (card) {
            card.addEventListener('click', function () {
                userInteracted = true;
                if (autoInterval) {
                    clearInterval(autoInterval);
                    autoInterval = null;
                }
            });
        });
    }

    // ============================================================
    // 5. STEPS SEQUENCED ANIMATION
    // ============================================================
    // Sequence: dot1 pops → text1 fades in → line grows to 50% →
    //           dot2 pops → text2 fades in → line grows to 100% →
    //           dot3 pops → text3 fades in
    if (!prefersReducedMotion) {
        var stepsGrid = document.querySelector('.steps-grid');
        var connectorFill = document.querySelector('.steps-connector-fill');
        var stepCards = stepsGrid ? stepsGrid.querySelectorAll('.step-card') : [];

        if (stepsGrid && stepCards.length) {
            // Mark grid for CSS animation states (cards start hidden)
            stepsGrid.classList.add('steps-animate');
            // Connector fill starts at 0 width, we'll animate via JS
            if (connectorFill) {
                connectorFill.style.width = '0';
                connectorFill.style.transition = 'none';
            }

            function runStepsSequence() {
                var delays = { dot: 500, text: 250, line: 600 };
                var t = 0;
                var lineStops = [50, 100]; // percentage stops between dots

                stepCards.forEach(function (card, i) {
                    // Pop the dot
                    setTimeout(function () {
                        card.classList.add('step-visible');
                    }, t);
                    t += delays.dot;

                    // Fade in title + description
                    var title = card.querySelector('.step-title');
                    var desc = card.querySelector('.step-desc');
                    if (title) {
                        (function (el, d) {
                            setTimeout(function () { el.style.animationDelay = '0ms'; }, d);
                        })(title, t);
                    }
                    if (desc) {
                        (function (el, d) {
                            setTimeout(function () { el.style.animationDelay = '120ms'; }, d);
                        })(desc, t);
                    }
                    t += delays.text;

                    // Grow the connector line to the next stop
                    if (connectorFill && i < stepCards.length - 1) {
                        (function (pct, d) {
                            setTimeout(function () {
                                connectorFill.style.transition =
                                    'width ' + delays.line + 'ms cubic-bezier(0.16, 1, 0.3, 1)';
                                connectorFill.style.width = pct + '%';
                            }, d);
                        })(lineStops[i], t);
                        t += delays.line;
                    }
                });
            }

            var stepsObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        // Small delay after reveal fade-in completes
                        setTimeout(runStepsSequence, 300);
                        stepsObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.3 });

            stepsObserver.observe(stepsGrid);
        }
    }

    // ============================================================
    // 6. INTERACTIVE FEATURES HALO + CARD GLOW
    // ============================================================
    var featuresSection = document.querySelector('.landing-features');
    var halo = document.getElementById('featuresHalo');
    var featureCards = document.querySelectorAll('.feature-card');

    if (featuresSection && halo && featureCards.length) {
        // Find mascot (home position for halo)
        var mascot = featuresSection.querySelector('.features-mascot');

        // Spring physics state
        var haloX = 0;
        var haloY = 0;
        var haloVX = 0;
        var haloVY = 0;
        var targetX = 0;
        var targetY = 0;

        // Spring constants
        var stiffness = 0.04;
        var damping = 0.75;

        var isOverSection = false;
        var isOverCard = false;
        var haloActive = false;

        // Get home position (mascot center, relative to section)
        function getHomePos() {
            if (!mascot) return { x: featuresSection.offsetWidth / 2, y: featuresSection.offsetHeight / 2 };
            var sRect = featuresSection.getBoundingClientRect();
            var mRect = mascot.getBoundingClientRect();
            return {
                x: mRect.left - sRect.left + mRect.width / 2,
                y: mRect.top - sRect.top + mRect.height / 2
            };
        }

        // Initialize position
        var home = getHomePos();
        haloX = home.x;
        haloY = home.y;
        targetX = home.x;
        targetY = home.y;
        halo.style.left = haloX + 'px';
        halo.style.top = haloY + 'px';

        // Card glow: track mouse position relative to each card
        featureCards.forEach(function (card) {
            card.addEventListener('mousemove', function (e) {
                var rect = card.getBoundingClientRect();
                var x = e.clientX - rect.left;
                var y = e.clientY - rect.top;
                card.style.setProperty('--glow-x', x + 'px');
                card.style.setProperty('--glow-y', y + 'px');
            });

            card.addEventListener('mouseenter', function () {
                isOverCard = true;
                var sRect = featuresSection.getBoundingClientRect();
                var cRect = card.getBoundingClientRect();
                targetX = cRect.left - sRect.left + cRect.width / 2;
                targetY = cRect.top - sRect.top + cRect.height / 2;
            });

            card.addEventListener('mouseleave', function () {
                isOverCard = false;
                var h = getHomePos();
                targetX = h.x;
                targetY = h.y;
            });
        });

        // Section mouse tracking
        featuresSection.addEventListener('mouseenter', function () {
            isOverSection = true;
            if (!haloActive) {
                haloActive = true;
                animateHalo();
            }
        });

        featuresSection.addEventListener('mousemove', function (e) {
            if (!isOverCard) {
                var sRect = featuresSection.getBoundingClientRect();
                targetX = e.clientX - sRect.left;
                targetY = e.clientY - sRect.top;
            }
        });

        featuresSection.addEventListener('mouseleave', function () {
            isOverSection = false;
            isOverCard = false;
            var h = getHomePos();
            targetX = h.x;
            targetY = h.y;
        });

        function animateHalo() {
            // Spring force
            var dx = targetX - haloX;
            var dy = targetY - haloY;

            var ax = dx * stiffness;
            var ay = dy * stiffness;

            haloVX = (haloVX + ax) * damping;
            haloVY = (haloVY + ay) * damping;

            haloX += haloVX;
            haloY += haloVY;

            halo.style.left = haloX + 'px';
            halo.style.top = haloY + 'px';

            // Keep animating if still moving or section is hovered
            var speed = Math.abs(haloVX) + Math.abs(haloVY);
            var distToTarget = Math.abs(dx) + Math.abs(dy);

            if (isOverSection || speed > 0.1 || distToTarget > 1) {
                requestAnimationFrame(animateHalo);
            } else {
                haloActive = false;
            }
        }
    }

})();
