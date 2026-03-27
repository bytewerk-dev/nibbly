/**
 * FAQ Accordion — expand/collapse with smooth animation
 * Uses max-height transition for CSS-driven animation.
 */
(function () {
    'use strict';

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        // Skip animation for users who prefer reduced motion
        document.querySelectorAll('.faq-item__question').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var item = btn.closest('.faq-item');
                var answer = item.querySelector('.faq-item__answer');
                var isOpen = item.classList.contains('faq-item--open');

                if (isOpen) {
                    item.classList.remove('faq-item--open');
                    answer.hidden = true;
                    answer.style.maxHeight = '';
                    btn.setAttribute('aria-expanded', 'false');
                } else {
                    item.classList.add('faq-item--open');
                    answer.hidden = false;
                    answer.style.maxHeight = answer.scrollHeight + 'px';
                    btn.setAttribute('aria-expanded', 'true');
                }
            });
        });
        return;
    }

    document.querySelectorAll('.faq-item__question').forEach(function (btn) {
        btn.addEventListener('click', function () {
            // Skip accordion toggle in edit mode — all answers are shown
            if (document.body.classList.contains('edit-mode-active')) return;

            var item = btn.closest('.faq-item');
            var answer = item.querySelector('.faq-item__answer');
            var isOpen = item.classList.contains('faq-item--open');

            if (isOpen) {
                // Collapse: set explicit max-height first, then trigger transition to 0
                answer.style.maxHeight = answer.scrollHeight + 'px';
                // Force reflow
                answer.offsetHeight;
                answer.style.maxHeight = '0';
                item.classList.remove('faq-item--open');
                btn.setAttribute('aria-expanded', 'false');

                answer.addEventListener('transitionend', function handler() {
                    answer.removeEventListener('transitionend', handler);
                    if (!item.classList.contains('faq-item--open')) {
                        answer.hidden = true;
                    }
                });
            } else {
                // Expand: unhide, measure, animate
                answer.hidden = false;
                answer.style.maxHeight = '0';
                // Force reflow
                answer.offsetHeight;
                answer.style.maxHeight = answer.scrollHeight + 'px';
                item.classList.add('faq-item--open');
                btn.setAttribute('aria-expanded', 'true');

                answer.addEventListener('transitionend', function handler() {
                    answer.removeEventListener('transitionend', handler);
                    if (item.classList.contains('faq-item--open')) {
                        // Allow content to grow if dynamic
                        answer.style.maxHeight = 'none';
                    }
                });
            }
        });
    });
})();
