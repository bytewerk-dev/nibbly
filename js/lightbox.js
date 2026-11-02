/**
 * Nibbly lightbox: image links marked with data-lightbox (image and gallery blocks, the cover of a
 * post, the gallery grid) open in an overlay instead of leaving the page. Links of the same
 * data-lightbox value form a group with previous/next. Not active while the inline editor is on
 * (body.edit-mode-active), where a click on the image opens the media library instead.
 */
(function () {
    'use strict';

    var IMAGE = /\.(jpe?g|png|gif|webp|avif|svg)(\?.*)?$/i;
    var overlay = null, image = null, caption = null, prevBtn = null, nextBtn = null, closeBtn = null;
    var group = [], index = 0, lastFocus = null;

    function links(groupName) {
        var all = Array.prototype.slice.call(document.querySelectorAll('a[data-lightbox], .gallery-item__link[data-gallery]'));
        return all.filter(function (a) {
            var name = a.getAttribute('data-lightbox') || 'gallery';
            return name === groupName && IMAGE.test(a.getAttribute('href') || '');
        });
    }

    function build() {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'gallery-lightbox';
        overlay.id = 'galleryLightbox';
        overlay.hidden = true;
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.innerHTML =
            '<button type="button" class="gallery-lightbox__close" aria-label="Close">&times;</button>' +
            '<button type="button" class="gallery-lightbox__nav gallery-lightbox__nav--prev" aria-label="Previous">&lsaquo;</button>' +
            '<figure class="gallery-lightbox__figure"><img class="gallery-lightbox__img" src="" alt=""><figcaption class="gallery-lightbox__caption"></figcaption></figure>' +
            '<button type="button" class="gallery-lightbox__nav gallery-lightbox__nav--next" aria-label="Next">&rsaquo;</button>';
        document.body.appendChild(overlay);
        image = overlay.querySelector('.gallery-lightbox__img');
        caption = overlay.querySelector('.gallery-lightbox__caption');
        prevBtn = overlay.querySelector('.gallery-lightbox__nav--prev');
        nextBtn = overlay.querySelector('.gallery-lightbox__nav--next');
        closeBtn = overlay.querySelector('.gallery-lightbox__close');
        closeBtn.addEventListener('click', close);
        prevBtn.addEventListener('click', function (e) { e.stopPropagation(); step(-1); });
        nextBtn.addEventListener('click', function (e) { e.stopPropagation(); step(1); });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay || e.target === image.parentNode) close();
        });
        document.addEventListener('keydown', function (e) {
            if (overlay.hidden) return;
            if (e.key === 'Escape') close();
            else if (e.key === 'ArrowLeft') step(-1);
            else if (e.key === 'ArrowRight') step(1);
        });
    }

    function show() {
        var link = group[index];
        if (!link) return;
        var img = link.querySelector('img');
        var figure = link.closest('figure');
        var text = figure && figure.querySelector('figcaption') ? figure.querySelector('figcaption').textContent.trim() : '';
        image.src = link.getAttribute('href');
        image.alt = img ? (img.getAttribute('alt') || '') : '';
        caption.textContent = text;
        caption.hidden = text === '';
        var many = group.length > 1;
        prevBtn.hidden = !many;
        nextBtn.hidden = !many;
    }

    function step(delta) {
        if (group.length < 2) return;
        index = (index + delta + group.length) % group.length;
        show();
    }

    function open(link) {
        build();
        group = links(link.getAttribute('data-lightbox') || 'gallery');
        index = Math.max(0, group.indexOf(link));
        if (!group.length) group = [link];
        lastFocus = document.activeElement;
        show();
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
        closeBtn.focus();
    }

    function close() {
        if (!overlay || overlay.hidden) return;
        overlay.hidden = true;
        image.src = '';
        document.body.style.overflow = '';
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    document.addEventListener('click', function (e) {
        if (document.body.classList.contains('edit-mode-active')) return;
        var link = e.target.closest ? e.target.closest('a[data-lightbox], .gallery-item__link[data-gallery]') : null;
        if (!link || !IMAGE.test(link.getAttribute('href') || '')) return;
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
        e.preventDefault();
        open(link);
    });
})();
