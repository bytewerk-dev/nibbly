/* nibbly comments: reply buttons move the form under the comment, submission goes over fetch with
   inline feedback (new token and arithmetic question come back with every answer). */
(function () {
    'use strict';
    document.querySelectorAll('.nb-comments').forEach(function (section) {
        var wrap = section.querySelector('.nb-comments__form-wrap');
        var form = section.querySelector('.nb-comments__form');
        if (!wrap || !form) return;
        var home = wrap.parentNode;
        var homeNext = wrap.nextSibling;
        var parentField = form.querySelector('input[name="parent"]');
        var replying = form.querySelector('.nb-comments__replying');
        var replyingText = form.querySelector('.nb-comments__replying-text');
        var cancel = form.querySelector('.nb-comments__cancel-reply');
        var feedback = form.querySelector('.nb-comments__feedback');
        var button = form.querySelector('button[type="submit"]');
        var textarea = form.querySelector('textarea[name="content"]');

        function resetReply() {
            parentField.value = '';
            if (replying) replying.hidden = true;
            if (wrap.parentNode !== home) home.insertBefore(wrap, homeNext);
        }

        section.addEventListener('click', function (event) {
            var reply = event.target.closest('.nb-comment__reply');
            if (!reply) return;
            var item = reply.closest('.nb-comment');
            if (!item) return;
            parentField.value = reply.getAttribute('data-reply-to') || '';
            if (replyingText) replyingText.textContent = (reply.getAttribute('data-reply-label') || 'Antwort an') + ' ' + (reply.getAttribute('data-reply-author') || '');
            if (replying) replying.hidden = false;
            var body = item.querySelector('.nb-comment__body');
            body.parentNode.insertBefore(wrap, body.nextSibling);
            if (textarea) textarea.focus();
        });
        if (cancel) cancel.addEventListener('click', resetReply);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!form.reportValidity || form.reportValidity()) {
                send();
            }
        });

        function setBusy(busy) {
            if (!button) return;
            button.disabled = busy;
            var text = button.querySelector('.btn-text');
            var loading = button.querySelector('.btn-loading');
            if (text) text.style.display = busy ? 'none' : '';
            if (loading) loading.style.display = busy ? '' : 'none';
        }

        function show(message, ok) {
            if (!feedback) return;
            feedback.textContent = message;
            feedback.className = 'form-feedback nb-comments__feedback ' + (ok ? 'success' : 'error');
        }

        function send() {
            setBusy(true);
            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: new FormData(form),
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
                credentials: 'same-origin'
            }).then(function (response) { return response.json(); }).then(function (data) {
                setBusy(false);
                if (data.formToken) form.querySelector('input[name="form_token"]').value = data.formToken;
                if (data.qcheck) form.querySelector('input[name="qcheck"]').value = data.qcheck;
                if (data.question) {
                    var label = form.querySelector('label[for$="-answer"]');
                    if (label) label.textContent = label.textContent.replace(/\d+\s*\+\s*\d+/, data.question);
                    var answer = form.querySelector('input[name="answer"]');
                    if (answer) answer.value = '';
                }
                show(data.message || '', !!data.success);
                if (!data.success) return;
                if (data.html) {
                    var parentId = parentField.value;
                    var target = parentId ? section.querySelector('#comment-' + parentId) : null;
                    var temp = document.createElement('div');
                    temp.innerHTML = data.html;
                    var newItem = temp.querySelector('.nb-comment');
                    if (target && newItem) {
                        var list = target.querySelector(':scope > ol');
                        if (!list) { list = document.createElement('ol'); list.className = 'nb-comments__list nb-comments__list--level-2'; target.appendChild(list); }
                        list.appendChild(newItem);
                    } else if (newItem) {
                        var rootList = section.querySelector(':scope > .nb-comments__list');
                        if (!rootList) {
                            rootList = document.createElement('ol'); rootList.className = 'nb-comments__list nb-comments__list--level-1';
                            var empty = section.querySelector('.nb-comments__empty');
                            if (empty) empty.replaceWith(rootList); else section.insertBefore(rootList, home === section ? wrap : home);
                        }
                        rootList.appendChild(newItem);
                    }
                }
                form.reset();
                resetReply();
            }).catch(function () {
                setBusy(false);
                show(form.getAttribute('data-error') || 'Fehler beim Senden.', false);
            });
        }
    });
})();
