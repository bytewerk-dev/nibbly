<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // Save content
    async function saveContent() {
        collectAllContent();

        currentContent.page = currentPage;
        currentContent.lang = document.getElementById('langSelect').value;

        try {
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('page', currentPage);
            formData.append('content', JSON.stringify(currentContent));
            formData.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                showToast(t('toast.saved'), 'success');
                isDirty = false;
                currentContent.lastModified = result.data.lastModified;
                if (result.data.seoHealth) {
                    const lang = document.getElementById('langSelect').value;
                    const page = document.getElementById('pageSelect').value;
                    currentSeoHealth = result.data.seoHealth;
                    setPageSeoHealth(lang, page, result.data.seoHealth);
                    updateEditorSeoHealth(result.data.seoHealth);
                }
                document.getElementById('lastModified').textContent =
                    t('editor.last_saved', {date: formatDateShort(result.data.lastModified)});
                loadBackups();
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_saving', {message: error.message}), 'error');
        }
    }

    // Load backups
    async function loadBackups() {
        try {
            const response = await fetch(`api.php?action=backups&page=${currentPage}`);
            const result = await response.json();

            if (result.success) {
                renderBackups(result.data);
            }
        } catch (error) {
            console.error('Error loading backups:', error);
        }
    }

    function renderBackups(backups) {
        const container = document.getElementById('backupList');

        if (backups.length === 0) {
            container.innerHTML = '<p style="color: #666;">' + t('backups.no_backups') + '</p>';
            return;
        }

        container.innerHTML = backups.map(backup => `
            <div class="backup-item">
                <div class="backup-info">
                    <span class="backup-date">${backup.date}</span>
                    <span class="backup-time">${backup.time}</span>
                </div>
                <div class="backup-actions">
                    <button class="btn btn-sm btn-secondary" onclick="previewBackup('${backup.filename}')">${t('backups.view')}</button>
                    <button class="btn btn-sm btn-primary" onclick="restoreBackup('${backup.filename}')">${t('backups.restore')}</button>
                    <button class="btn btn-sm btn-danger" onclick="deleteBackup('${backup.filename}')">${t('backups.delete')}</button>
                </div>
            </div>
        `).join('');
    }

    async function previewBackup(filename) {
        try {
            const response = await fetch(`api.php?action=preview-backup&backup=${filename}`);
            const result = await response.json();

            if (result.success) {
                showModal(
                    t('backups.view'),
                    renderBackupPreview(result.data),
                    closeModal,
                    {
                        html: true,
                        modalClass: 'modal-backup-preview',
                        confirmText: t('btn.close'),
                        confirmClass: 'btn btn-primary'
                    }
                );
            } else {
                showToast(result.message, 'error');
            }
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
    }

    function flattenBackupPreviewFields(value, prefix, rows) {
        if (Array.isArray(value)) {
            if (value.length === 0) {
                rows.push({ name: prefix || '[]', value: '[]' });
                return;
            }
            var allScalar = value.every(function(item) {
                return item === null || typeof item !== 'object';
            });
            if (allScalar) {
                rows.push({
                    name: prefix || '[]',
                    value: value.map(formatBackupPreviewValue).join(', ')
                });
                return;
            }
            value.forEach(function(item, index) {
                flattenBackupPreviewFields(item, (prefix ? prefix : '') + '[' + index + ']', rows);
            });
            return;
        }

        if (value && typeof value === 'object') {
            var keys = Object.keys(value);
            if (keys.length === 0) {
                rows.push({ name: prefix || '{}', value: '{}' });
                return;
            }
            keys.forEach(function(key) {
                flattenBackupPreviewFields(value[key], prefix ? prefix + '.' + key : key, rows);
            });
            return;
        }

        rows.push({ name: prefix || 'value', value: formatBackupPreviewValue(value) });
    }

    function formatBackupPreviewValue(value) {
        if (value === null) return 'null';
        if (value === undefined) return '';
        if (typeof value === 'boolean') return value ? 'true' : 'false';
        if (typeof value === 'number') return String(value);
        var text = String(value);
        return text === '' ? '—' : text;
    }

    function renderBackupPreview(data) {
        var rows = [];
        flattenBackupPreviewFields(data, '', rows);
        if (!rows.length) {
            return '<div class="backup-preview-modal"><p class="backup-preview-empty">Keine Inhalte im Backup gefunden.</p></div>';
        }
        return '<div class="backup-preview-modal">' + rows.map(function(row) {
            return '<article class="backup-preview-field">' +
                '<div class="backup-preview-field__name" title="' + escapeHtml(row.name) + '">' + escapeHtml(row.name) + '</div>' +
                '<div class="backup-preview-field__value">' + escapeHtml(row.value) + '</div>' +
            '</article>';
        }).join('') + '</div>';
    }

    function restoreBackup(filename) {
        showModal(t('modal.restore_backup'),
            t('modal.restore_backup_confirm'),
            async () => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'restore');
                    formData.append('backup', filename);
                    formData.append('csrf_token', CSRF_TOKEN);

                    const response = await fetch('api.php', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        showToast(t('toast.backup_restored'), 'success');
                        closeModal();
                        loadContent();
                    } else {
                        showToast(result.message, 'error');
                    }
                } catch (error) {
                    showToast(t('toast.error_generic', {message: error.message}), 'error');
                }
            }
        );
    }

    function deleteBackup(filename) {
        showModal(t('modal.delete_backup'),
            t('modal.delete_backup_confirm'),
            async () => {
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete-backup');
                    formData.append('backup', filename);
                    formData.append('csrf_token', CSRF_TOKEN);

                    const response = await fetch('api.php', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        showToast(t('toast.backup_deleted'), 'success');
                        closeModal();
                        loadBackups();
                    } else {
                        showToast(result.message, 'error');
                    }
                } catch (error) {
                    showToast(t('toast.error_generic', {message: error.message}), 'error');
                }
            }
        );
    }

    // Modal
    function closeAllComboboxes() {
        document.querySelectorAll('.nb-combobox__list:not([hidden])').forEach(function(list) {
            list.hidden = true;
            var root = list.closest('.nb-combobox');
            if (root) {
                var input = root.querySelector('input[role="combobox"]');
                if (input) input.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function showModal(title, text, onConfirm, options) {
        options = options || {};
        closeAllComboboxes();
        var modalEl = document.querySelector('#modalOverlay .modal');
        if (modalEl) {
            modalEl.className = 'modal' + (options.modalClass ? ' ' + options.modalClass : '');
        }
        document.getElementById('modalTitle').textContent = title;
        var modalText = document.getElementById('modalText');
        if (options.html) {
            modalText.innerHTML = text;
        } else {
            modalText.textContent = text;
        }
        document.getElementById('modalOverlay').style.display = 'flex';
        document.getElementById('modalOverlay').setAttribute('aria-hidden', 'false');
        var confirmBtn = document.getElementById('modalConfirm');
        confirmBtn.onclick = onConfirm || closeModal;
        if (options.confirmText) confirmBtn.textContent = options.confirmText;
        if (options.confirmClass) confirmBtn.className = options.confirmClass;
        if (options.hideConfirm) confirmBtn.style.display = 'none';
        setTimeout(() => {
            var focusTarget = options.focusSelector ? document.querySelector(options.focusSelector) : confirmBtn;
            (focusTarget || confirmBtn).focus();
        }, 0);
    }

    function closeModal() {
        const overlay = document.getElementById('modalOverlay');
        overlay.style.display = 'none';
        overlay.setAttribute('aria-hidden', 'true');
        const modalEl = overlay.querySelector('.modal');
        if (modalEl) modalEl.className = 'modal';
        // Reset confirm button to default state
        const btn = document.getElementById('modalConfirm');
        btn.textContent = t('btn.confirm');
        btn.className = 'btn btn-danger';
        btn.disabled = false;
        btn.style.display = '';
    }

    // Format date without seconds
    function formatDateShort(dateStr) {
        const d = new Date(dateStr);
        return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    // Toast
    function showToast(message, type = 'success') {
        const existing = document.querySelector('.toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
        toast.setAttribute('aria-atomic', 'true');
        toast.textContent = message;
        document.body.appendChild(toast);

        const duration = type === 'error' ? 6000 : 4000;
        setTimeout(() => {
            toast.classList.add('toast-fade-out');
            toast.addEventListener('animationend', () => toast.remove());
        }, duration);
    }

    // HTML escape
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Init
    document.getElementById('langSelect').addEventListener('change', function() {
        updatePageSelect();
        const m = document.getElementById('langSelectMobile');
        if (m) m.value = this.value;
    });
    const langMobile = document.getElementById('langSelectMobile');
    if (langMobile) {
        langMobile.addEventListener('change', function() {
            document.getElementById('langSelect').value = this.value;
            updatePageSelect();
        });
    }
    const pageMobile = document.getElementById('pageSelectMobile');
    if (pageMobile) {
        pageMobile.addEventListener('change', function() {
            document.getElementById('pageSelect').value = this.value;
        });
    }
    document.getElementById('pageSelect').addEventListener('change', function() {
        if (pageMobile) pageMobile.value = this.value;
    });

    const DASHBOARD_PATH = window.location.pathname.replace(/\/dashboard\.php$/, '/dashboard');
