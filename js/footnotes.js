/**
 * Nibbly footnotes: <span class="footnote">Text</span> in running text becomes a superscript
 * number with a tooltip (hover, focus, tap). The editors insert the span (inline editor and the
 * dashboard's rich editor); while the inline editor's edit mode is active the original markup is
 * restored so the footnote text can be edited in place.
 */
(function () {
    'use strict';

    var LABELS = { de: 'Fußnote', en: 'Footnote', es: 'Nota', fr: 'Note', it: 'Nota', pt: 'Nota', tr: 'Dipnot', cs: 'Poznámka', pl: 'Przypis' };
    var lang = (document.documentElement.lang || 'en').slice(0, 2).toLowerCase();
    var label = LABELS[lang] || LABELS.en;

    function placeFootnote(note) {
        var text = note.querySelector('.footnote__text');
        if (!text) return;
        text.style.left = '';
        text.style.transform = '';
        var noteRect = note.getBoundingClientRect();
        var width = text.offsetWidth;
        var left = noteRect.left + noteRect.width / 2 - width / 2;
        var min = 16, max = window.innerWidth - 16 - width;
        if (left < min || left > max) {
            text.style.left = (Math.min(Math.max(left, min), max) - noteRect.left) + 'px';
            text.style.transform = 'none';
        }
    }

    function closeFootnotes(except) {
        document.querySelectorAll('.footnote--open').forEach(function (note) {
            if (note === except) return;
            note.classList.remove('footnote--open');
            var mark = note.querySelector('.footnote__mark');
            if (mark) mark.setAttribute('aria-expanded', 'false');
        });
    }

    function enhanceFootnotes() {
        var number = 0;
        document.querySelectorAll('.footnote:not(.footnote--ready)').forEach(function (note) {
            if (note.closest('.editor-wysiwyg, .nb-page-rich-editor, #admin-bar')) return;
            number += 1;
            var text = document.createElement('span');
            text.className = 'footnote__text';
            text.setAttribute('role', 'tooltip');
            text.id = 'footnote-' + number;
            while (note.firstChild) text.appendChild(note.firstChild);
            var mark = document.createElement('button');
            mark.type = 'button';
            mark.className = 'footnote__mark';
            mark.textContent = String(number);
            mark.setAttribute('aria-label', label + ' ' + number);
            mark.setAttribute('aria-expanded', 'false');
            mark.setAttribute('aria-describedby', text.id);
            note.appendChild(mark);
            note.appendChild(text);
            note.classList.add('footnote--ready');
            mark.addEventListener('click', function (event) {
                event.preventDefault();
                var open = !note.classList.contains('footnote--open');
                closeFootnotes(note);
                note.classList.toggle('footnote--open', open);
                mark.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) placeFootnote(note);
            });
            note.addEventListener('mouseenter', function () { placeFootnote(note); });
            mark.addEventListener('focus', function () { placeFootnote(note); });
        });
    }

    function restoreFootnotes() {
        document.querySelectorAll('.footnote--ready').forEach(function (note) {
            var text = note.querySelector('.footnote__text');
            note.textContent = '';
            if (text) while (text.firstChild) note.appendChild(text.firstChild);
            note.classList.remove('footnote--ready', 'footnote--open');
        });
    }

    document.addEventListener('click', function (event) {
        if (!event.target.closest || !event.target.closest('.footnote--open')) closeFootnotes();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeFootnotes();
    });
    window.addEventListener('resize', function () {
        document.querySelectorAll('.footnote--open').forEach(placeFootnote);
    });
    if (window.MutationObserver) {
        new MutationObserver(function () {
            if (document.body.classList.contains('edit-mode-active')) restoreFootnotes();
            else enhanceFootnotes();
        }).observe(document.body, { attributes: true, attributeFilter: ['class'] });
    }
    if (!document.body.classList.contains('edit-mode-active')) enhanceFootnotes();
})();
