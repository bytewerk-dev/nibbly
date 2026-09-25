/**
 * AI disclosure labels on touch devices (EU AI Act, Art. 50).
 *
 * Without hover, a label is shown once the visitor lingers on its image: the
 * image is at least 60 % visible and the page has not scrolled for 700 ms.
 * Scrolling quickly past keeps the label hidden; it is hidden again when the
 * image leaves the viewport. Hover devices are handled by css/components.css.
 */
(function () {
    'use strict';

    if (!window.matchMedia || !window.matchMedia('(hover: none)').matches) return;
    var labels = Array.prototype.slice.call(document.querySelectorAll('.nb-ai-label'));
    if (!labels.length) return;
    var timer = null;

    function visible(frame) {
        var rect = frame.getBoundingClientRect();
        if (!rect.height) return false;
        var shown = Math.min(rect.bottom, window.innerHeight) - Math.max(rect.top, 0);
        return shown >= Math.min(rect.height, window.innerHeight) * 0.6;
    }

    function check(lingered) {
        labels.forEach(function (label) {
            if (!visible(label.parentElement)) label.classList.remove('is-revealed');
            else if (lingered) label.classList.add('is-revealed');
        });
    }

    function wait() {
        clearTimeout(timer);
        timer = setTimeout(function () { check(true); }, 700);
    }

    window.addEventListener('scroll', function () { check(false); wait(); }, { passive: true });
    wait();
})();
