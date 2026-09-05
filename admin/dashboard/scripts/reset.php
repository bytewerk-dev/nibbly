<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // ============================================================
    // TOTAL RESET
    // ============================================================

    (function() {
        var input = document.getElementById('totalResetConfirm');
        var btn = document.getElementById('totalResetBtn');

        input.addEventListener('input', function() {
            btn.disabled = (input.value !== 'DELETE');
        });

        btn.addEventListener('click', async function() {
            if (input.value !== 'DELETE') {
                showToast(t('settings.total_reset_mismatch'), 'error');
                return;
            }

            btn.disabled = true;
            btn.textContent = '...';

            try {
                var formData = new FormData();
                formData.append('action', 'total-reset');
                formData.append('confirm', 'DELETE');
                formData.append('csrf_token', CSRF_TOKEN);

                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();

                if (result.success) {
                    showToast(t('toast.total_reset_success'), 'success');
                    setTimeout(function() {
                        window.location.href = 'setup.php';
                    }, 1500);
                } else {
                    showToast(result.message, 'error');
                    btn.disabled = false;
                    btn.textContent = t('settings.total_reset_btn');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
                btn.disabled = false;
                btn.textContent = t('settings.total_reset_btn');
            }
        });
    })();
