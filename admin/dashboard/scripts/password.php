<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // ============================================================
    // CHANGE PASSWORD
    // ============================================================

    (function() {
        var newPw = document.getElementById('newPassword');
        var confirmPw = document.getElementById('newPasswordConfirm');
        var form = document.getElementById('changePasswordForm');

        var reqs = {
            length:  function() { return newPw.value.length >= 8; },
            upper:   function() { return /[A-Z]/.test(newPw.value); },
            lower:   function() { return /[a-z]/.test(newPw.value); },
            digit:   function() { return /[0-9]/.test(newPw.value); },
            special: function() { return /[^A-Za-z0-9]/.test(newPw.value); },
            match:   function() { return newPw.value.length > 0 && newPw.value === confirmPw.value; }
        };

        function updateReqs() {
            for (var key in reqs) {
                var el = document.querySelector('#pwReqs [data-req="' + key + '"]');
                if (el) {
                    if (reqs[key]()) {
                        el.classList.add('met');
                        el.classList.remove('unmet');
                    } else {
                        el.classList.remove('met');
                        el.classList.add('unmet');
                    }
                }
            }
        }

        newPw.addEventListener('input', updateReqs);
        confirmPw.addEventListener('input', updateReqs);

        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            var btn = document.getElementById('changePwBtn');
            btn.disabled = true;
            btn.textContent = t('btn.changing');

            try {
                var formData = new FormData();
                formData.append('action', 'change-password');
                formData.append('current_password', document.getElementById('currentPassword').value);
                formData.append('new_password', newPw.value);
                formData.append('new_password_confirm', confirmPw.value);
                formData.append('csrf_token', CSRF_TOKEN);

                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();

                if (result.success) {
                    showToast(t('toast.password_changed'), 'success');
                    form.reset();
                    updateReqs();

                    // Remove password warning banner if present
                    var warning = document.getElementById('passwordWarning');
                    if (warning) warning.remove();
                    var adminMain = document.getElementById('adminMain');
                    if (adminMain) adminMain.classList.remove('has-security-warning');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast(t('toast.error_generic', {message: error.message}), 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = t('settings.change_password');
            }
        });
    })();
