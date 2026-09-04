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
                '<td>' + (user.lastLogin ? new Date(user.lastLogin).toLocaleString() : '—') + '</td>' +
                '<td class="users-table__actions">' +
                    '<button class="btn btn-sm btn-secondary" onclick="editUser(\'' + user.id + '\')" title="' + t('pages.edit') + '">' + t('pages.edit') + '</button> ' +
                    '<button class="btn-icon" onclick="resetUserPassword(\'' + user.id + '\', \'' + escapeHtml(user.username) + '\')" title="' + t('settings.reset_password') + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></button> ' +
                    (isCurrentUser ? '' : '<button class="btn-icon btn-icon--danger" onclick="deleteUserConfirm(\'' + user.id + '\', \'' + escapeHtml(user.username) + '\')" title="' + t('btn.delete') + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>') +
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
    document.getElementById('addUserBtn').addEventListener('click', function() {
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
    document.getElementById('userGenPwBtn').addEventListener('click', function() {
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
    document.getElementById('userForm').addEventListener('submit', async function(e) {
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
    document.getElementById('resetPwGenBtn').addEventListener('click', function() {
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
    document.getElementById('resetPwInput').addEventListener('input', function() {
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
    document.getElementById('resetPwForm').addEventListener('submit', async function(e) {
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

    // Load users when the users panel becomes visible
    var _usersLoaded = false;
    var _menuOrderLoaded = false;
    // Watch for settings tab switches to load data on demand
    document.querySelectorAll('.settings-tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (this.getAttribute('data-settings-action')) return;
            var tab = this.getAttribute('data-settings-tab');
            if (!tab) return;
            loadSettingsTabData(tab);
        });
    });

    // ============================================================
    // MENU ORDER
    // ============================================================

    var _menuOrderItems = [];

    var menuOrderSelect = document.getElementById('menuOrderSelect');
    var menuOrderList = document.getElementById('menuOrderList');
    var menuOrderEmpty = document.getElementById('menuOrderEmpty');
    var saveMenuOrderBtn = document.getElementById('saveMenuOrderBtn');

    if (menuOrderSelect) {
        menuOrderSelect.addEventListener('change', function() {
            loadMenuOrder();
        });
    }

    if (saveMenuOrderBtn) {
        saveMenuOrderBtn.addEventListener('click', function() {
            saveMenuOrder();
        });
    }

    async function loadMenuOrder() {
        var menuId = menuOrderSelect ? menuOrderSelect.value : '';
        if (!menuId) return;

        var defaultLang = '<?php echo defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'; ?>';

        try {
            var resp = await fetch('api.php?action=get-menu-items&menu=' + encodeURIComponent(menuId) + '&lang=' + encodeURIComponent(defaultLang));
            var result = await resp.json();
            if (result.success && result.data && result.data.items) {
                _menuOrderItems = result.data.items;
                renderMenuOrderList();
            } else {
                _menuOrderItems = [];
                renderMenuOrderList();
            }
        } catch (e) {
            showToast(t('toast.error'), 'error');
        }
    }

    function renderMenuOrderList() {
        if (!menuOrderList) return;

        if (_menuOrderItems.length === 0) {
            menuOrderList.style.display = 'none';
            menuOrderEmpty.style.display = 'block';
            if (saveMenuOrderBtn) saveMenuOrderBtn.disabled = true;
            return;
        }

        menuOrderList.style.display = 'block';
        menuOrderEmpty.style.display = 'none';
        if (saveMenuOrderBtn) saveMenuOrderBtn.disabled = false;

        var dragGripSvg = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11 18c0 1.1-.9 2-2 2s-2-.9-2-2 .9-2 2-2 2 .9 2 2zm-2-8c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0-6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm6 4c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>';

        var html = '';
        _menuOrderItems.forEach(function(item, i) {
            html += '<div class="menu-order-item" data-index="' + i + '" draggable="true">';
            html += '<span class="menu-order-item__drag-handle">' + dragGripSvg + '</span>';
            html += '<span class="menu-order-item__label">' + escapeHtml(item.label || item.page || '') + '</span>';
            html += '<span class="menu-order-item__slug">' + escapeHtml(item.page || '') + '</span>';
            html += '<span class="menu-order-item__actions">';
            html += '<button type="button" class="btn-icon" title="' + t('btn.move_up') + '"' + (i === 0 ? ' disabled' : '') + ' onclick="moveMenuItem(' + i + ', -1)">';
            html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>';
            html += '</button>';
            html += '<button type="button" class="btn-icon" title="' + t('btn.move_down') + '"' + (i === _menuOrderItems.length - 1 ? ' disabled' : '') + ' onclick="moveMenuItem(' + i + ', 1)">';
            html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>';
            html += '</button>';
            html += '</span>';
            html += '</div>';
        });
        menuOrderList.innerHTML = html;
        initMenuDragAndDrop();
    }

    function moveMenuItem(index, direction) {
        var newIndex = index + direction;
        if (newIndex < 0 || newIndex >= _menuOrderItems.length) return;
        var item = _menuOrderItems.splice(index, 1)[0];
        _menuOrderItems.splice(newIndex, 0, item);
        renderMenuOrderList();
    }

    // Drag and drop for menu order items
    var _menuDragIndex = null;

    function initMenuDragAndDrop() {
        var items = menuOrderList.querySelectorAll('.menu-order-item');
        items.forEach(function(el) {
            el.addEventListener('dragstart', function(e) {
                _menuDragIndex = parseInt(this.dataset.index);
                this.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            el.addEventListener('dragend', function() {
                _menuDragIndex = null;
                this.classList.remove('dragging');
                items.forEach(function(item) {
                    item.classList.remove('drag-over-top', 'drag-over-bottom');
                });
            });
            el.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                var rect = this.getBoundingClientRect();
                var midY = rect.top + rect.height / 2;
                this.classList.remove('drag-over-top', 'drag-over-bottom');
                if (e.clientY < midY) {
                    this.classList.add('drag-over-top');
                } else {
                    this.classList.add('drag-over-bottom');
                }
            });
            el.addEventListener('dragleave', function() {
                this.classList.remove('drag-over-top', 'drag-over-bottom');
            });
            el.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('drag-over-top', 'drag-over-bottom');
                var targetIndex = parseInt(this.dataset.index);
                if (_menuDragIndex === null || _menuDragIndex === targetIndex) return;

                var rect = this.getBoundingClientRect();
                var midY = rect.top + rect.height / 2;
                var insertBefore = e.clientY < midY;

                var item = _menuOrderItems.splice(_menuDragIndex, 1)[0];
                var newIndex = insertBefore ? targetIndex : targetIndex + 1;
                if (_menuDragIndex < targetIndex) newIndex--;
                _menuOrderItems.splice(newIndex, 0, item);
                renderMenuOrderList();
            });
        });
    }

    async function saveMenuOrder() {
        var menuId = menuOrderSelect ? menuOrderSelect.value : '';
        if (!menuId) return;

        var defaultLang = '<?php echo defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'; ?>';
        var order = _menuOrderItems.map(function(item) { return item.page || ''; }).filter(Boolean);

        var formData = new FormData();
        formData.append('action', 'save-menu-order');
        formData.append('menu', menuId);
        formData.append('lang', defaultLang);
        formData.append('order', JSON.stringify(order));
        formData.append('csrf_token', CSRF_TOKEN);

        try {
            var resp = await fetch('api.php', { method: 'POST', body: formData });
            var result = await resp.json();
            if (result.success) {
                showToast(t('settings.menu_order_saved'), 'success');
            } else {
                showToast(result.message || t('toast.error'), 'error');
            }
        } catch (e) {
            showToast(t('toast.error'), 'error');
        }
    }

    <?php endif; ?>
