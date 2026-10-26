<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // ---- Site languages (Settings → Language) --------------------------------
    // Reorder the language switcher and add a language as a copy of an existing
    // one (includes/site-languages.php). Both rewrite the site configuration, so
    // the dashboard reloads afterwards.
    (function() {
        const list = document.getElementById('siteLangList');
        const saveOrderBtn = document.getElementById('saveSiteLangOrderBtn');
        const addBtn = document.getElementById('addSiteLangBtn');
        if (!list || !saveOrderBtn || !addBtn) return;

        const currentOrder = () => Array.from(list.querySelectorAll('.site-lang-item')).map(item => item.dataset.code);
        const savedOrder = currentOrder().join(',');

        function syncMoveButtons() {
            const items = Array.from(list.querySelectorAll('.site-lang-item'));
            items.forEach((item, index) => {
                item.querySelector('[data-site-lang-move="up"]').disabled = index === 0;
                item.querySelector('[data-site-lang-move="down"]').disabled = index === items.length - 1;
            });
            saveOrderBtn.disabled = currentOrder().join(',') === savedOrder;
        }

        list.addEventListener('click', function(event) {
            const button = event.target.closest('[data-site-lang-move]');
            if (!button) return;
            const item = button.closest('.site-lang-item');
            if (button.dataset.siteLangMove === 'up' && item.previousElementSibling) {
                list.insertBefore(item, item.previousElementSibling);
            } else if (button.dataset.siteLangMove === 'down' && item.nextElementSibling) {
                list.insertBefore(item.nextElementSibling, item);
            }
            syncMoveButtons();
            button.focus();
        });

        async function post(action, fields) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('csrf_token', CSRF_TOKEN);
            Object.keys(fields).forEach(key => formData.append(key, fields[key]));
            const response = await fetch('api.php', { method: 'POST', body: formData });
            return response.json();
        }

        saveOrderBtn.addEventListener('click', async function() {
            saveOrderBtn.disabled = true;
            try {
                const result = await post('reorder-site-languages', { order: JSON.stringify(currentOrder()) });
                if (!result.success) throw new Error(result.message || '');
                showToast(t('languages.order_saved'), 'success');
                setTimeout(() => location.reload(), 800);
            } catch (error) {
                showToast(t('toast.error_generic', { message: error.message }), 'error');
                syncMoveButtons();
            }
        });

        addBtn.addEventListener('click', function() {
            const codeSelect = document.getElementById('newSiteLang');
            const fromSelect = document.getElementById('newSiteLangFrom');
            if (!codeSelect || !codeSelect.value) return;
            const name = codeSelect.options[codeSelect.selectedIndex].textContent.trim();
            const fromName = fromSelect.options[fromSelect.selectedIndex].textContent.trim();
            showModal(
                t('languages.add'),
                t('languages.add_confirm', { language: name, source: fromName }),
                async function() {
                    addBtn.disabled = true;
                    try {
                        const result = await post('add-site-language', { code: codeSelect.value, from: fromSelect.value });
                        if (!result.success) throw new Error(result.message || '');
                        const copied = result.data || {};
                        showToast(t('languages.added', { language: name, pages: copied.pages || 0 }), 'success');
                        setTimeout(() => location.reload(), 1000);
                    } catch (error) {
                        showToast(t('toast.error_generic', { message: error.message }), 'error');
                        addBtn.disabled = false;
                    }
                }
            );
        });

        if (!document.getElementById('newSiteLang')?.options.length) addBtn.disabled = true;
        syncMoveButtons();
    })();
