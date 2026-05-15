(function() {
    'use strict';

    function decode(value) {
        try {
            return atob(value || '');
        } catch (e) {
            return '';
        }
    }

    document.querySelectorAll('[data-nibbly-email]').forEach(function(el) {
        var email = decode(el.getAttribute('data-nibbly-email'));
        if (!email) return;
        var query = decode(el.getAttribute('data-nibbly-email-query'));

        if (el.tagName.toLowerCase() === 'a') {
            el.setAttribute('href', 'mailto:' + email + (query || ''));
        }

        if (!el.dataset.nibblyEmailLabel) {
            el.textContent = email;
        }
    });
})();
