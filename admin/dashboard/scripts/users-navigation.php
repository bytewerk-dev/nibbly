<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>

    // ============================================================
    // USER MANAGEMENT
    // ============================================================

    <?php if ($isAdminUser): ?>
    var CURRENT_USER_ID = <?php echo json_encode($_SESSION['admin_user_id'] ?? ''); ?>;

    function loadUsers() {
        fetch('api.php?action=list-users&csrf_token=' + encodeURIComponent(CSRF_TOKEN))
            .then(r => r.json())
            .then(result => {
                if (!result.success) return;
                renderUsersTable(result.data);
            });
    }

    function renderUsersTable(users) {
        var tbody = document.getElementById('usersTableBody');
        if (!tbody) return;
        tbody.innerHTML = '';
        users.forEach(function(user) {
            var isCurrentUser = user.id === CURRENT_USER_ID;
            var tr = document.createElement('tr');
            if (isCurrentUser) tr.classList.add('users-table__current');
            var roleLabel = user.role.charAt(0).toUpperCase() + user.role.slice(1);
            tr.innerHTML =
                '<td>' + escapeHtml(user.username) + (isCurrentUser ? ' <em>(' + t('settings.user_you') + ')</em>' : '') + '</td>' +
                '<td>' + escapeHtml(user.email || '—') + '</td>' +
                '<td><span class="role-badge role-badge--' + user.role + '">' + roleLabel + '</span></td>' +
                '<td>' + (user.lastLogin ? nbFormatDate(user.lastLogin, 'datetime') : '—') + '</td>' +
                '<td class="users-table__actions">' +
                    '<button type="button" class="btn btn-sm btn-secondary" onclick="editUser(\'' + user.id + '\')" title="' + t('pages.edit') + '">' + t('pages.edit') + '</button> ' +
                    // Your own password is changed under My account, which asks for the current one
                    (isCurrentUser
                        ? '<button type="button" class="btn btn-sm btn-secondary" onclick="switchTab(\'settings\', {settingsTab: \'my-account\'})">' + t('settings.my_account') + '</button> '
                        : '<button type="button" class="btn btn-sm btn-secondary" onclick="resetUserPassword(\'' + user.id + '\', \'' + escapeHtml(user.username) + '\')" title="' + t('settings.reset_password') + '">' + t('settings.password') + '</button> ') +
                    (isCurrentUser ? '' : '<button type="button" class="btn-icon btn-icon--danger" onclick="deleteUserConfirm(\'' + user.id + '\', \'' + escapeHtml(user.username) + '\')" title="' + t('btn.delete') + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>') +
                '</td>';
            tbody.appendChild(tr);
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function generatePassword() {
        var upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        var lower = 'abcdefghjkmnpqrstuvwxyz';
        var digits = '23456789';
        var special = '!@#$%&*+-=?';
        var all = upper + lower + digits + special;
        var arr = new Uint32Array(20);
        crypto.getRandomValues(arr);
        var pw = '';
        pw += upper[arr[0] % upper.length];
        pw += lower[arr[1] % lower.length];
        pw += digits[arr[2] % digits.length];
        pw += special[arr[3] % special.length];
        for (var i = 4; i < 16; i++) pw += all[arr[i] % all.length];
        var a = pw.split('');
        var s = new Uint32Array(a.length);
        crypto.getRandomValues(s);
        for (var j = a.length - 1; j > 0; j--) {
            var k = s[j] % (j + 1);
            var tmp = a[j]; a[j] = a[k]; a[k] = tmp;
        }
        return a.join('');
    }

    // Open add user modal
    onElement('addUserBtn', 'click', function() {
        document.getElementById('userFormId').value = '';
        document.getElementById('userFormUsername').value = '';
        document.getElementById('userFormEmail').value = '';
        document.getElementById('userFormRole').value = 'editor';
        document.getElementById('userFormPassword').value = '';
        document.getElementById('userFormPasswordGroup').style.display = '';
        document.getElementById('userGeneratedPw').style.display = 'none';
        document.getElementById('userModalTitle').textContent = t('settings.add_user');
        document.getElementById('userFormPassword').required = true;
        closeAllComboboxes();
        document.getElementById('userModalOverlay').style.display = 'flex';
    });

    function closeUserModal() {
        document.getElementById('userModalOverlay').style.display = 'none';
    }

    // Generate password in user modal
    onElement('userGenPwBtn', 'click', function() {
        var pw = generatePassword();
        document.getElementById('userFormPassword').value = pw;
        document.getElementById('userFormPassword').type = 'text';
        document.getElementById('userGeneratedPwText').textContent = pw;
        document.getElementById('userGeneratedPw').style.display = 'flex';
        setTimeout(function() {
            document.getElementById('userFormPassword').type = 'password';
        }, 30000);
    });

    // Edit user
    var _usersCache = [];
    function editUser(userId) {
        fetch('api.php?action=list-users&csrf_token=' + encodeURIComponent(CSRF_TOKEN))
            .then(r => r.json())
            .then(result => {
                if (!result.success) return;
                var user = result.data.find(u => u.id === userId);
                if (!user) return;
                document.getElementById('userFormId').value = user.id;
                document.getElementById('userFormUsername').value = user.username;
                document.getElementById('userFormEmail').value = user.email || '';
                document.getElementById('userFormRole').value = user.role;
                document.getElementById('userFormPassword').value = '';
                document.getElementById('userFormPasswordGroup').style.display = 'none';
                document.getElementById('userGeneratedPw').style.display = 'none';
                document.getElementById('userModalTitle').textContent = t('settings.edit_user');
                document.getElementById('userFormPassword').required = false;
                closeAllComboboxes();
                document.getElementById('userModalOverlay').style.display = 'flex';
            });
    }

    // Submit user form (add or edit)
    onElement('userForm', 'submit', async function(e) {
        e.preventDefault();
        var userId = document.getElementById('userFormId').value;
        var isEdit = !!userId;

        var formData = new FormData();
        formData.append('action', isEdit ? 'update-user' : 'create-user');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('username', document.getElementById('userFormUsername').value);
        formData.append('email', document.getElementById('userFormEmail').value);
        formData.append('role', document.getElementById('userFormRole').value);
        if (isEdit) {
            formData.append('user_id', userId);
        } else {
            formData.append('password', document.getElementById('userFormPassword').value);
        }

        try {
            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();
            if (result.success) {
                closeUserModal();
                loadUsers();
                showToast(result.message, 'success');
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
    });

    // Reset password for a user
    function resetUserPassword(userId, username) {
        document.getElementById('resetPwUserId').value = userId;
        document.getElementById('resetPwInput').value = '';
        document.getElementById('resetPwGenerated').style.display = 'none';
        document.getElementById('resetPwModalTitle').textContent = t('settings.reset_password') + ' — ' + username;
        // Reset requirement indicators
        document.querySelectorAll('#resetPwReqs .requirement').forEach(function(el) { el.classList.remove('met'); });
        closeAllComboboxes();
        document.getElementById('resetPwModalOverlay').style.display = 'flex';
        setTimeout(function() { document.getElementById('resetPwInput').focus(); }, 100);
    }

    function closeResetPwModal() {
        document.getElementById('resetPwModalOverlay').style.display = 'none';
    }

    // Generate password in reset modal
    onElement('resetPwGenBtn', 'click', function() {
        var pw = generatePassword();
        document.getElementById('resetPwInput').value = pw;
        document.getElementById('resetPwInput').type = 'text';
        document.getElementById('resetPwGeneratedText').textContent = pw;
        document.getElementById('resetPwGenerated').style.display = 'flex';
        validatePasswordRequirements(pw, '#resetPwReqs');
        setTimeout(function() {
            document.getElementById('resetPwInput').type = 'password';
        }, 30000);
    });

    // Live validation for reset password
    onElement('resetPwInput', 'input', function() {
        validatePasswordRequirements(this.value, '#resetPwReqs');
    });

    function validatePasswordRequirements(pw, containerSel) {
        var container = document.querySelector(containerSel);
        if (!container) return;
        var checks = {
            length: pw.length >= 8,
            upper: /[A-Z]/.test(pw),
            lower: /[a-z]/.test(pw),
            digit: /[0-9]/.test(pw),
            special: /[^A-Za-z0-9]/.test(pw)
        };
        Object.keys(checks).forEach(function(key) {
            var el = container.querySelector('[data-req="' + key + '"]');
            if (el) el.classList.toggle('met', checks[key]);
        });
    }

    // Submit reset password form
    onElement('resetPwForm', 'submit', async function(e) {
        e.preventDefault();
        var userId = document.getElementById('resetPwUserId').value;
        var pw = document.getElementById('resetPwInput').value;

        var formData = new FormData();
        formData.append('action', 'admin-reset-password');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('user_id', userId);
        formData.append('password', pw);

        try {
            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();
            if (result.success) {
                closeResetPwModal();
                showToast(result.message, 'success');
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
    });

    // Delete user
    function deleteUserConfirm(userId, username) {
        showModal(
            t('settings.delete_user'),
            t('settings.delete_user_confirm', {username: username}),
            function() {
                var formData = new FormData();
                formData.append('action', 'delete-user');
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('user_id', userId);

                fetch('api.php', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(result => {
                        if (result.success) {
                            closeModal();
                            loadUsers();
                            showToast(result.message, 'success');
                        } else {
                            showToast(result.message, 'error');
                        }
                    });
            }
        );
    }

    // Users load once their panel is first opened (loadSettingsTabData in scripts/settings.php)
    var _usersLoaded = false;

    <?php endif; ?>
