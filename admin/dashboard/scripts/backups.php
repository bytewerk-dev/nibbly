<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // ============================================================
    // SITE BACKUP
    // ============================================================

    (function() {
        var btn = document.getElementById('createSiteBackupBtn');
        var progress = document.getElementById('backupProgress');
        var progressText = document.getElementById('backupProgressText');

        btn.addEventListener('click', async function() {
            btn.disabled = true;
            btn.style.display = 'none';
            progress.style.display = 'flex';

            try {
                var formData = new FormData();
                formData.append('action', 'create-site-backup');
                formData.append('csrf_token', CSRF_TOKEN);

                var response = await fetch('api.php', { method: 'POST', body: formData });
                var result = await response.json();

                if (result.success) {
                    progressText.textContent = t('settings.backup_downloading');

                    var downloadUrl = 'api.php?action=download-site-backup'
                        + '&token=' + encodeURIComponent(result.data.token)
                        + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
                        + '&filename=' + encodeURIComponent(result.data.filename);

                    var a = document.createElement('a');
                    a.href = downloadUrl;
                    a.download = result.data.filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);

                    showToast(t('toast.backup_site_created'), 'success');
                } else {
                    showToast(t('toast.backup_site_failed', {message: result.message}), 'error');
                }
            } catch (error) {
                showToast(t('toast.backup_site_failed', {message: error.message}), 'error');
            }

            setTimeout(function() {
                btn.disabled = false;
                btn.style.display = '';
                progress.style.display = 'none';
                progressText.textContent = t('settings.backup_creating');
            }, 2000);
        });
    })();

    // ============================================================
    // RESTORE FROM BACKUP
    // ============================================================

    (function() {
        var fileInput = document.getElementById('restoreFileInput');
        var selectBtn = document.getElementById('restoreSelectBtn');
        var filenameEl = document.getElementById('restoreFilename');
        var modeSelector = document.getElementById('restoreModeSelector');
        var actionsEl = document.getElementById('restoreActions');
        var restoreBtn = document.getElementById('restoreBtn');
        var progress = document.getElementById('restoreProgress');
        var progressText = document.getElementById('restoreProgressText');

        selectBtn.addEventListener('click', function() {
            fileInput.click();
        });

        fileInput.addEventListener('change', function() {
            var file = fileInput.files[0];
            if (file) {
                filenameEl.textContent = file.name + ' (' + (file.size / 1024 / 1024).toFixed(1) + ' MB)';
                filenameEl.style.display = '';
                selectBtn.querySelector('span').textContent = t('settings.restore_change_file');
                modeSelector.style.display = '';
                actionsEl.style.display = '';
            } else {
                filenameEl.style.display = 'none';
                selectBtn.querySelector('span').textContent = t('settings.restore_select_file');
                modeSelector.style.display = 'none';
                actionsEl.style.display = 'none';
            }
        });

        restoreBtn.addEventListener('click', function() {
            var file = fileInput.files[0];
            if (!file) return;

            var mode = document.querySelector('input[name="restore_mode"]:checked').value;
            var warningKey = mode === 'full' ? 'settings.restore_warning_full' : 'settings.restore_warning_content';

            showModal(t('settings.restore_title'), t(warningKey), async function() {
                closeModal();
                restoreBtn.disabled = true;
                restoreBtn.style.display = 'none';
                progress.style.display = 'flex';

                try {
                    var formData = new FormData();
                    formData.append('action', 'restore-site-backup');
                    formData.append('csrf_token', CSRF_TOKEN);
                    formData.append('restore_mode', mode);
                    formData.append('backup_zip', file);

                    var response = await fetch('api.php', { method: 'POST', body: formData });
                    var result = await response.json();

                    if (result.success) {
                        var toastKey = mode === 'full' ? 'toast.restore_success_full' : 'toast.restore_success_content';
                        showToast(t(toastKey, {extracted: result.data.extracted}), 'success');
                        setTimeout(function() { location.reload(); }, 2000);
                    } else {
                        showToast(t('toast.restore_failed', {message: result.message}), 'error');
                        restoreBtn.disabled = false;
                        restoreBtn.style.display = '';
                        progress.style.display = 'none';
                    }
                } catch (error) {
                    showToast(t('toast.restore_failed', {message: error.message}), 'error');
                    restoreBtn.disabled = false;
                    restoreBtn.style.display = '';
                    progress.style.display = 'none';
                }
            });
        });
    })();

    // ============================================================
    // SCHEDULED / AUTOMATED BACKUPS
    // ============================================================

    (function() {
        var statusEl       = document.getElementById('scheduledStatus');
        var lastRunEl      = document.getElementById('scheduledLastRun');
        var statusMsgEl    = document.getElementById('scheduledStatusMessage');
        var storageCountEl = document.getElementById('scheduledStorageCount');
        var storageDatesEl = document.getElementById('scheduledStorageDates');
        var form           = document.getElementById('scheduledBackupForm');
        var enabledEl      = document.getElementById('scheduledEnabled');
        var cronModeEls    = Array.from(document.querySelectorAll('input[name="scheduledCronMode"]'));
        var webCronUrlEl   = document.getElementById('webCronUrl');
        var dailyEl        = document.getElementById('retentionDaily');
        var weeklyEl       = document.getElementById('retentionWeekly');
        var monthlyEl      = document.getElementById('retentionMonthly');
        var yearlyEl       = document.getElementById('retentionYearly');
        var limitEl        = document.getElementById('storageLimitMb');
        var saveBtn        = document.getElementById('scheduledSaveBtn');
        var listBody       = document.getElementById('scheduledListBody');
        var cronCopyBtns   = Array.from(document.querySelectorAll('[data-copy-code]'));
        var remoteListEl   = document.getElementById('remoteTargetList');
        var remoteAddBtn   = document.getElementById('remoteAddBtn');
        var remoteSelectEl = document.getElementById('remoteProviderSelect');
        var remoteTargets  = [];
        var remoteProviders = {};
        var remoteFileCache = {};

        if (!statusEl) return; // Tab not on this dashboard

        var remoteFieldLabels = {
            app_key: t('settings.remote_field_app_key'),
            access_token: t('settings.remote_field_access_token'),
            refresh_token: t('settings.remote_field_refresh_token'),
            client_id: t('settings.remote_field_client_id'),
            client_secret: t('settings.remote_field_client_secret'),
            path: t('settings.remote_field_path'),
            folder_id: t('settings.remote_field_folder_id'),
            folder_path: t('settings.remote_field_folder_path'),
            host: t('settings.remote_field_host'),
            port: t('settings.remote_field_port'),
            username: t('settings.remote_field_username'),
            password: t('settings.remote_field_password'),
            remote_path: t('settings.remote_field_remote_path'),
            ssl: t('settings.remote_field_ssl'),
            passive: t('settings.remote_field_passive'),
            endpoint: t('settings.remote_field_endpoint'),
            region: t('settings.remote_field_region'),
            bucket: t('settings.remote_field_bucket'),
            prefix: t('settings.remote_field_prefix'),
            access_key: t('settings.remote_field_access_key'),
            secret_key: t('settings.remote_field_secret_key'),
            path_style: t('settings.remote_field_path_style'),
            url: t('settings.remote_field_url'),
            bearer_token: t('settings.remote_field_bearer_token')
        };

        var remotePlaceholders = {
            dropbox: { app_key: 'Dropbox app key', path: '/nibbly Backups' },
            google_drive: { client_id: 'Google OAuth client ID', client_secret: t('settings.remote_placeholder_optional'), folder_id: t('settings.remote_placeholder_optional') },
            onedrive: { client_id: 'Microsoft app client ID', client_secret: t('settings.remote_placeholder_optional'), folder_path: '/nibbly Backups' },
            sftp: { port: '22', remote_path: '/home/user/backups' },
            ftp: { port: '21', remote_path: 'backups', ssl: '0', passive: '1' },
            s3: { endpoint: 'https://s3.example.com', region: 'eu-central-1', prefix: 'nibbly', path_style: '0' },
            webdav: { url: 'https://cloud.example.com/remote.php/dav/files/user/backups' }
        };

        var remoteProviderHints = {
            sftp: t('settings.remote_hint_sftp'),
            ftp: t('settings.remote_hint_ftp')
        };

        var remoteDefaultSettings = {
            sftp: { port: '22' },
            ftp: { port: '21', passive: true }
        };

        function fmtSize(bytes) {
            if (!bytes) return '0 B';
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
            if (bytes >= 1048576)    return (bytes / 1048576).toFixed(1) + ' MB';
            if (bytes >= 1024)       return (bytes / 1024).toFixed(1) + ' KB';
            return bytes + ' B';
        }
        function fmtDate(unixOrIso) {
            if (!unixOrIso) return '—';
            var d = (typeof unixOrIso === 'number') ? new Date(unixOrIso * 1000) : new Date(unixOrIso);
            if (isNaN(d.getTime())) return '—';
            return d.toLocaleString();
        }
        function selectedCronMode() {
            var checked = cronModeEls.find(function(input) { return input.checked; });
            return checked ? checked.value : 'server';
        }
        function setCronMode(mode) {
            cronModeEls.forEach(function(input) {
                input.checked = input.value === (mode === 'web' ? 'web' : 'server');
            });
        }
        function webCronUrl(token) {
            if (!token) return '—';
            var url = new URL('../api/backup-cron.php', window.location.href);
            url.searchParams.set('token', token);
            return url.toString();
        }

        async function refresh() {
            try {
                var res = await fetch('api.php?action=backup-status');
                var json = await res.json();
                if (!json.success) throw new Error(json.message);
                var s = json.data;

                // Form fields
                enabledEl.checked = !!s.enabled;
                setCronMode(s.cron_mode || 'server');
                if (webCronUrlEl) webCronUrlEl.textContent = webCronUrl(s.web_cron_token || '');
                dailyEl.value     = s.retention.daily;
                weeklyEl.value    = s.retention.weekly;
                monthlyEl.value   = s.retention.monthly;
                yearlyEl.value    = s.retention.yearly;
                limitEl.value     = s.storage_limit_mb;
                remoteTargets     = s.remote_targets || [];
                remoteProviders   = s.remote_providers || {};
                renderRemoteTargets();

                // Last run + cron health
                if (s.last_run) {
                    lastRunEl.textContent = fmtDate(s.last_run);
                    var ageMs = Date.now() - new Date(s.last_run).getTime();
                    var ageDays = Math.floor(ageMs / 86400000);
                    if (s.last_status === 'error') {
                        statusEl.dataset.state = 'error';
                        statusMsgEl.textContent = t('settings.last_run_error', { message: s.last_message || '' });
                    } else if (s.enabled && ageDays >= 2) {
                        statusEl.dataset.state = 'warning';
                        statusMsgEl.textContent = t('settings.last_run_warning', { days: ageDays });
                    } else {
                        statusEl.dataset.state = 'ok';
                        statusMsgEl.textContent = t('settings.last_run_ok');
                    }
                } else {
                    lastRunEl.textContent = t('settings.last_run_never');
                    if (s.enabled) {
                        statusEl.dataset.state = 'warning';
                        statusMsgEl.textContent = t('settings.last_run_warning', { days: '∞' });
                    } else {
                        statusEl.dataset.state = 'idle';
                        statusMsgEl.textContent = '';
                    }
                }

                // Storage summary
                storageCountEl.textContent = t('settings.storage_count', {
                    count: s.count, size: fmtSize(s.total_bytes)
                });
                if (s.oldest && s.newest && s.count > 0) {
                    storageDatesEl.textContent = ' · '
                        + t('settings.storage_oldest', { date: fmtDate(s.oldest) })
                        + ' · '
                        + t('settings.storage_newest', { date: fmtDate(s.newest) });
                } else {
                    storageDatesEl.textContent = '';
                }

                // Backup list
                var listRes = await fetch('api.php?action=backup-list');
                var listJson = await listRes.json();
                if (!listJson.success) throw new Error(listJson.message);
                var backups = listJson.data.backups || [];
                renderList(backups);
            } catch (err) {
                statusEl.dataset.state = 'error';
                statusMsgEl.textContent = err.message || String(err);
            }
        }

        function renderList(backups) {
            if (backups.length === 0) {
                listBody.innerHTML = '<tr><td colspan="4" class="scheduled-list__empty">'
                    + t('settings.backup_list_empty') + '</td></tr>';
                return;
            }
            listBody.innerHTML = '';
            backups.forEach(function(b) {
                var tr = document.createElement('tr');

                var tdDate = document.createElement('td');
                tdDate.textContent = fmtDate(b.mtime);
                tr.appendChild(tdDate);

                var tdTier = document.createElement('td');
                var tierBadge = document.createElement('span');
                tierBadge.className = 'scheduled-tier scheduled-tier--' + b.tier;
                tierBadge.textContent = b.tier;
                tdTier.appendChild(tierBadge);
                tr.appendChild(tdTier);

                var tdSize = document.createElement('td');
                tdSize.textContent = fmtSize(b.size);
                tr.appendChild(tdSize);

                var tdActions = document.createElement('td');
                tdActions.className = 'scheduled-list__actions';

                var dlBtn = document.createElement('button');
                dlBtn.className = 'btn btn-secondary btn-sm';
                dlBtn.textContent = t('settings.backup_download');
                dlBtn.onclick = function() { downloadBackup(b.file); };
                tdActions.appendChild(dlBtn);

                var rsBtn = document.createElement('button');
                rsBtn.className = 'btn btn-secondary btn-sm';
                rsBtn.textContent = t('settings.backup_restore_from_pool');
                rsBtn.onclick = function() { restoreBackup(b.file); };
                tdActions.appendChild(rsBtn);

                var delBtn = document.createElement('button');
                delBtn.className = 'btn btn-danger btn-sm';
                delBtn.textContent = t('settings.backup_delete');
                delBtn.onclick = function() { deleteBackup(b.file); };
                tdActions.appendChild(delBtn);

                if (remoteTargets.length > 0) {
                    var upBtn = document.createElement('button');
                    upBtn.className = 'btn btn-secondary btn-sm';
                    upBtn.textContent = t('settings.remote_upload');
                    upBtn.onclick = function() { uploadBackupRemote(b.file, upBtn); };
                    tdActions.appendChild(upBtn);
                }

                tr.appendChild(tdActions);
                listBody.appendChild(tr);
            });
        }

        function targetLabel(type) {
            return (remoteProviders[type] && remoteProviders[type].label) || type;
        }

        function localizeRemoteMessage(message) {
            var text = message || '';
            var map = {
                'Dropbox access token is missing.': t('settings.remote_error_dropbox_access_token_missing'),
                'Google Drive access token is missing.': t('settings.remote_error_google_access_token_missing'),
                'OneDrive access token is missing.': t('settings.remote_error_onedrive_access_token_missing'),
                'PHP ssh2 extension is required for SFTP/SCP uploads.': t('settings.remote_error_ssh2_required'),
                'PHP FTP extension is required for FTP/FTPS uploads.': t('settings.remote_error_ftp_required')
            };
            return map[text] || text;
        }

        function makeTarget(type) {
            return {
                id: type + '-' + Date.now().toString(36),
                type: type,
                name: targetLabel(type),
                enabled: true,
                settings: Object.assign({}, remoteDefaultSettings[type] || {}),
                last_upload: null,
                last_status: null,
                last_message: null,
                last_file: null
            };
        }

        function collectRemoteTargets() {
            if (!remoteListEl) return [];
            return Array.from(remoteListEl.querySelectorAll('.backup-remote-target')).map(function(card) {
                var target = {
                    id: card.dataset.id,
                    type: card.dataset.type,
                    name: card.querySelector('[data-field="name"]').value.trim(),
                    enabled: card.querySelector('[data-field="enabled"]').checked,
                    settings: {},
                    last_upload: card.dataset.lastUpload || null,
                    last_status: card.dataset.lastStatus || null,
                    last_message: card.dataset.lastMessage || null,
                    last_file: card.dataset.lastFile || null
                };
                card.querySelectorAll('[data-setting]').forEach(function(input) {
                    target.settings[input.dataset.setting] = input.type === 'checkbox' ? input.checked : input.value;
                });
                return target;
            });
        }

        function renderRemoteTargets() {
            if (!remoteListEl) return;
            if (!remoteTargets.length) {
                remoteListEl.innerHTML = '<div class="backup-remote__empty">' + t('settings.remote_empty') + '</div>';
                return;
            }
            remoteListEl.innerHTML = '';
            remoteTargets.forEach(function(target, idx) {
                var provider = remoteProviders[target.type] || {};
                var oauthProvider = target.type === 'dropbox' || target.type === 'google_drive' || target.type === 'onedrive';
                var card = document.createElement('div');
                card.className = 'backup-remote-target';
                card.dataset.id = target.id;
                card.dataset.type = target.type;
                card.dataset.lastUpload = target.last_upload || '';
                card.dataset.lastStatus = target.last_status || '';
                card.dataset.lastMessage = target.last_message || '';
                card.dataset.lastFile = target.last_file || '';

                var header = document.createElement('div');
                header.className = 'backup-remote-target__header';
                var title = document.createElement('div');
                title.className = 'backup-remote-target__title';
                var enabled = document.createElement('input');
                enabled.type = 'checkbox';
                enabled.checked = !!target.enabled;
                enabled.dataset.field = 'enabled';
                title.appendChild(enabled);
                var name = document.createElement('input');
                name.type = 'text';
                name.value = target.name || targetLabel(target.type);
                name.dataset.field = 'name';
                title.appendChild(name);
                var badge = document.createElement('span');
                badge.textContent = targetLabel(target.type);
                title.appendChild(badge);
                header.appendChild(title);

                var actions = document.createElement('div');
                actions.className = 'backup-remote-target__actions';
                if (target.type === 'dropbox' || target.type === 'google_drive' || target.type === 'onedrive') {
                    var connectBtn = document.createElement('button');
                    connectBtn.type = 'button';
                    connectBtn.className = 'btn btn-secondary btn-sm';
                    connectBtn.textContent = target.type === 'dropbox'
                        ? t('settings.remote_connect_dropbox')
                        : (target.type === 'google_drive' ? t('settings.remote_connect_google') : t('settings.remote_connect_onedrive'));
                    connectBtn.onclick = function() { connectOAuthTarget(target.id, target.type); };
                    actions.appendChild(connectBtn);
                }
                var testBtn = document.createElement('button');
                testBtn.type = 'button';
                testBtn.className = 'btn btn-secondary btn-sm';
                testBtn.textContent = t('settings.remote_test');
                testBtn.onclick = function() { testRemoteTarget(target.id); };
                actions.appendChild(testBtn);
                var filesBtn = document.createElement('button');
                filesBtn.type = 'button';
                filesBtn.className = 'btn btn-secondary btn-sm';
                filesBtn.textContent = t('settings.remote_files');
                filesBtn.onclick = function() { loadRemoteFiles(target.id); };
                actions.appendChild(filesBtn);
                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn-danger btn-sm';
                removeBtn.textContent = t('btn.delete');
                removeBtn.onclick = function() {
                    remoteTargets.splice(idx, 1);
                    renderRemoteTargets();
                };
                actions.appendChild(removeBtn);
                header.appendChild(actions);
                card.appendChild(header);

                var grid = document.createElement('div');
                grid.className = 'backup-remote-target__grid';
                (provider.fields || []).forEach(function(field) {
                    if (oauthProvider && (field === 'access_token' || field === 'refresh_token')) return;
                    if (target.type === 'dropbox' && provider.has_global_oauth && field === 'app_key') return;
                    if ((target.type === 'google_drive' || target.type === 'onedrive') && provider.has_global_oauth && (field === 'client_id' || field === 'client_secret')) return;
                    var label = document.createElement('label');
                    if (field === 'path_style' || field === 'ssl' || field === 'passive') label.className = 'backup-remote-target__check';
                    var span = document.createElement('span');
                    span.textContent = remoteFieldLabels[field] || field;
                    label.appendChild(span);
                    var input = document.createElement('input');
                    input.dataset.setting = field;
                    if (field === 'path_style' || field === 'ssl' || field === 'passive') {
                        input.type = 'checkbox';
                        input.checked = !!(target.settings && target.settings[field]);
                    } else {
                        input.type = 'text';
                        input.value = (target.settings && target.settings[field]) || '';
                        input.placeholder = (remotePlaceholders[target.type] && remotePlaceholders[target.type][field]) || '';
                        input.name = 'nibbly_remote_' + target.type + '_' + field + '_' + target.id;
                        input.id = 'nibbly_remote_' + target.id + '_' + field;
                        input.autocomplete = 'off';
                        input.autocapitalize = 'off';
                        input.spellcheck = false;
                        input.setAttribute('data-lpignore', 'true');
                        input.setAttribute('data-1p-ignore', 'true');
                        input.setAttribute('data-form-type', 'other');
                        if ((provider.secret_fields || []).indexOf(field) !== -1) {
                            input.className = 'backup-remote-target__secret';
                        }
                    }
                    label.appendChild(input);
                    grid.appendChild(label);
                });
                card.appendChild(grid);

                if (remoteProviderHints[target.type]) {
                    var hint = document.createElement('p');
                    hint.className = 'form-hint backup-remote-target__hint';
                    hint.textContent = remoteProviderHints[target.type];
                    card.appendChild(hint);
                }

                var status = document.createElement('div');
                status.className = 'backup-remote-target__status';
                status.dataset.state = target.last_status || 'idle';
                status.textContent = target.last_upload
                    ? t('settings.remote_last_upload', { date: fmtDate(target.last_upload), message: localizeRemoteMessage(target.last_message || '') })
                    : t('settings.remote_not_tested');
                card.appendChild(status);
                if (remoteFileCache[target.id]) {
                    card.appendChild(renderRemoteFileList(target.id, remoteFileCache[target.id]));
                }
                remoteListEl.appendChild(card);
            });
        }

        function renderRemoteFileList(targetId, files) {
            var box = document.createElement('div');
            box.className = 'backup-remote-files';
            if (!files.length) {
                box.textContent = t('settings.remote_files_empty');
                return box;
            }
            files.forEach(function(file) {
                var row = document.createElement('div');
                row.className = 'backup-remote-file';
                var meta = document.createElement('div');
                meta.className = 'backup-remote-file__meta';
                var name = document.createElement('strong');
                name.textContent = file.file;
                var details = document.createElement('span');
                details.textContent = file.tier + ' · ' + fmtSize(file.size) + ' · ' + fmtDate(file.mtime);
                meta.appendChild(name);
                meta.appendChild(details);
                row.appendChild(meta);
                var actions = document.createElement('div');
                actions.className = 'backup-remote-file__actions';
                var importBtn = document.createElement('button');
                importBtn.type = 'button';
                importBtn.className = 'btn btn-secondary btn-sm';
                importBtn.textContent = t('settings.remote_import');
                importBtn.onclick = function() { importRemoteBackup(targetId, file.file); };
                actions.appendChild(importBtn);
                var deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'btn btn-danger btn-sm';
                deleteBtn.textContent = t('btn.delete');
                deleteBtn.onclick = function() { deleteRemoteBackup(targetId, file.file); };
                actions.appendChild(deleteBtn);
                row.appendChild(actions);
                box.appendChild(row);
            });
            return box;
        }

        async function saveRemoteSettingsOnly() {
            remoteTargets = collectRemoteTargets();
            var fd = new FormData();
            fd.append('action', 'backup-update-settings');
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('remote_targets', JSON.stringify(remoteTargets));
            var res = await fetch('api.php', { method: 'POST', body: fd });
            var json = await res.json();
            if (!json.success) throw new Error(json.message);
            remoteTargets = json.data.remote_targets || remoteTargets;
            remoteProviders = json.data.remote_providers || remoteProviders;
            renderRemoteTargets();
        }

        async function testRemoteTarget(targetId) {
            try {
                await saveRemoteSettingsOnly();
                var fd = new FormData();
                fd.append('action', 'backup-test-remote');
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('target_id', targetId);
                var res = await fetch('api.php', { method: 'POST', body: fd });
                var json = await res.json();
                if (!json.success) throw new Error(json.message);
                showToast(t('toast.remote_test_success'), 'success');
                refresh();
            } catch (err) {
                showToast(t('toast.remote_test_failed', { message: localizeRemoteMessage(err.message || String(err)) }), 'error');
                refresh();
            }
        }

        async function connectOAuthTarget(targetId, type) {
            var popup = window.open('about:blank', '_blank');
            try {
                await saveRemoteSettingsOnly();
                var target = remoteTargets.find(function(tg) { return tg.id === targetId; });
                var requiredField = type === 'dropbox' ? 'app_key' : 'client_id';
                var provider = remoteProviders[type] || {};
                if (!target || !target.settings || (!target.settings[requiredField] && !provider.has_global_oauth)) {
                    if (popup) popup.close();
                    showToast(t(type === 'dropbox' ? 'toast.remote_dropbox_app_key_missing' : 'toast.remote_client_id_missing'), 'error');
                    return;
                }
                var action = type === 'dropbox'
                    ? 'backup-dropbox-oauth-start'
                    : (type === 'google_drive' ? 'backup-google-oauth-start' : 'backup-onedrive-oauth-start');
                var url = 'api.php?action=' + action
                    + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
                    + '&target_id=' + encodeURIComponent(targetId);
                if (popup) {
                    popup.location = url;
                } else {
                    window.location.href = url;
                }
            } catch (err) {
                if (popup) popup.close();
                showToast(t('toast.remote_oauth_connect_failed', { message: localizeRemoteMessage(err.message || String(err)) }), 'error');
            }
        }

        async function uploadBackupRemote(file, button) {
            var originalHtml = button ? button.innerHTML : '';
            var actionButtons = [];
            function setBusy(isBusy) {
                if (!button) return;
                var row = button.closest('tr');
                actionButtons = row ? Array.from(row.querySelectorAll('button')) : [button];
                actionButtons.forEach(function(btn) { btn.disabled = isBusy; });
                button.classList.toggle('is-loading', isBusy);
                button.setAttribute('aria-busy', isBusy ? 'true' : 'false');
                button.innerHTML = isBusy
                    ? '<span class="btn-spinner" aria-hidden="true"></span><span>' + t('settings.remote_uploading') + '</span>'
                    : originalHtml;
            }
            try {
                setBusy(true);
                await saveRemoteSettingsOnly();
                var enabledTargets = remoteTargets.filter(function(tg) { return tg.enabled; });
                if (!enabledTargets.length) {
                    showToast(t('toast.remote_no_enabled_targets'), 'error');
                    return;
                }
                var fd = new FormData();
                fd.append('action', 'backup-upload-remote');
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('file', file);
                var res = await fetch('api.php', { method: 'POST', body: fd });
                var json = await res.json();
                if (!json.success) throw new Error(json.message);
                showToast(t('toast.remote_upload_success'), 'success');
                refresh();
            } catch (err) {
                showToast(t('toast.remote_upload_failed', { message: localizeRemoteMessage(err.message || String(err)) }), 'error');
                refresh();
            } finally {
                setBusy(false);
            }
        }

        async function loadRemoteFiles(targetId) {
            try {
                var res = await fetch('api.php?action=backup-remote-list&target_id=' + encodeURIComponent(targetId));
                var json = await res.json();
                if (!json.success) throw new Error(json.message);
                remoteFileCache[targetId] = json.data.files || [];
                renderRemoteTargets();
            } catch (err) {
                showToast(t('toast.remote_list_failed', { message: localizeRemoteMessage(err.message || String(err)) }), 'error');
            }
        }

        async function importRemoteBackup(targetId, file) {
            try {
                var fd = new FormData();
                fd.append('action', 'backup-remote-import');
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('target_id', targetId);
                fd.append('file', file);
                var res = await fetch('api.php', { method: 'POST', body: fd });
                var json = await res.json();
                if (!json.success) throw new Error(json.message);
                showToast(t('toast.remote_import_success'), 'success');
                refresh();
            } catch (err) {
                showToast(t('toast.remote_import_failed', { message: localizeRemoteMessage(err.message || String(err)) }), 'error');
            }
        }

        function deleteRemoteBackup(targetId, file) {
            showModal(t('settings.remote_delete'), t('settings.remote_delete_confirm'), async function() {
                closeModal();
                try {
                    var fd = new FormData();
                    fd.append('action', 'backup-remote-delete');
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('target_id', targetId);
                    fd.append('file', file);
                    var res = await fetch('api.php', { method: 'POST', body: fd });
                    var json = await res.json();
                    if (!json.success) throw new Error(json.message);
                    showToast(t('toast.remote_delete_success'), 'success');
                    await loadRemoteFiles(targetId);
                } catch (err) {
                    showToast(t('toast.remote_delete_failed', { message: localizeRemoteMessage(err.message || String(err)) }), 'error');
                }
            });
        }

        async function downloadBackup(file) {
            try {
                var fd = new FormData();
                fd.append('action', 'backup-prepare-download');
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('file', file);
                var res = await fetch('api.php', { method: 'POST', body: fd });
                var json = await res.json();
                if (!json.success) throw new Error(json.message);
                var url = 'api.php?action=download-site-backup'
                    + '&token=' + encodeURIComponent(json.data.token)
                    + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
                    + '&filename=' + encodeURIComponent(json.data.filename);
                var a = document.createElement('a');
                a.href = url;
                a.download = json.data.filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            } catch (err) {
                showToast(t('toast.backup_site_failed', { message: err.message || String(err) }), 'error');
            }
        }

        function restoreBackup(file) {
            showModal(
                t('settings.restore_title'),
                '<div class="modal-choice-list">' +
                    '<label class="modal-choice">' +
                        '<input type="radio" name="restoreMode" value="content" checked>' +
                        '<span><strong>' + escapeHtml(t('settings.restore_content')) + '</strong><small>' + escapeHtml(t('settings.restore_content_desc')) + '</small></span>' +
                    '</label>' +
                    '<label class="modal-choice">' +
                        '<input type="radio" name="restoreMode" value="full">' +
                        '<span><strong>' + escapeHtml(t('settings.restore_full')) + '</strong><small>' + escapeHtml(t('settings.restore_full_desc')) + '</small></span>' +
                    '</label>' +
                '</div>',
                function() {
                    var selected = document.querySelector('input[name="restoreMode"]:checked');
                    var mode = selected ? selected.value : 'content';
                    closeModal();

                    var warningKey = mode === 'full'
                        ? 'settings.backup_restore_pool_warning_full'
                        : 'settings.backup_restore_pool_warning_content';

                    showModal(t('settings.restore_title'), t(warningKey), async function() {
                        closeModal();
                        try {
                            var fd = new FormData();
                            fd.append('action', 'restore-site-backup');
                            fd.append('csrf_token', CSRF_TOKEN);
                            fd.append('restore_mode', mode);
                            fd.append('pool_file', file);
                            var res = await fetch('api.php', { method: 'POST', body: fd });
                            var json = await res.json();
                            if (json.success) {
                                var toastKey = mode === 'full' ? 'toast.restore_success_full' : 'toast.restore_success_content';
                                showToast(t(toastKey, { extracted: json.data.extracted }), 'success');
                                setTimeout(function() { location.reload(); }, 2000);
                            } else {
                                showToast(t('toast.restore_failed', { message: json.message }), 'error');
                            }
                        } catch (err) {
                            showToast(t('toast.restore_failed', { message: err.message || String(err) }), 'error');
                        }
                    });
                },
                {
                    html: true,
                    confirmText: t('settings.restore_title'),
                    confirmClass: 'btn btn-primary'
                }
            );
        }

        function deleteBackup(file) {
            showModal(t('settings.backup_delete'), t('settings.backup_delete_confirm'), async function() {
                closeModal();
                try {
                    var fd = new FormData();
                    fd.append('action', 'backup-delete');
                    fd.append('csrf_token', CSRF_TOKEN);
                    fd.append('file', file);
                    var res = await fetch('api.php', { method: 'POST', body: fd });
                    var json = await res.json();
                    if (json.success) {
                        showToast(t('toast.backup_deleted'), 'success');
                        refresh();
                    } else {
                        showToast(t('toast.backup_delete_failed', { message: json.message }), 'error');
                    }
                } catch (err) {
                    showToast(t('toast.backup_delete_failed', { message: err.message || String(err) }), 'error');
                }
            });
        }

        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            saveBtn.disabled = true;
            try {
                var fd = new FormData();
                fd.append('action', 'backup-update-settings');
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('enabled', enabledEl.checked ? 'true' : 'false');
                fd.append('cron_mode', selectedCronMode());
                fd.append('retention_daily', dailyEl.value);
                fd.append('retention_weekly', weeklyEl.value);
                fd.append('retention_monthly', monthlyEl.value);
                fd.append('retention_yearly', yearlyEl.value);
                fd.append('storage_limit_mb', limitEl.value);
                remoteTargets = collectRemoteTargets();
                fd.append('remote_targets', JSON.stringify(remoteTargets));
                var res = await fetch('api.php', { method: 'POST', body: fd });
                var json = await res.json();
                if (json.success) {
                    showToast(t('toast.scheduled_backup_settings_saved'), 'success');
                    refresh();
                } else {
                    showToast(t('toast.scheduled_backup_settings_failed', { message: json.message }), 'error');
                }
            } catch (err) {
                showToast(t('toast.scheduled_backup_settings_failed', { message: err.message || String(err) }), 'error');
            } finally {
                saveBtn.disabled = false;
            }
        });

        if (remoteAddBtn) {
            remoteAddBtn.addEventListener('click', function() {
                remoteTargets = collectRemoteTargets();
                remoteTargets.push(makeTarget(remoteSelectEl.value));
                renderRemoteTargets();
            });
        }

        window.addEventListener('message', function(event) {
            if (event.origin === window.location.origin && event.data && event.data.type === 'nibbly:backup-oauth') {
                refresh();
                showToast(t('toast.remote_oauth_connected'), 'success');
            }
        });

        cronCopyBtns.forEach(function(cronCopyBtn) {
            cronCopyBtn.addEventListener('click', function() {
                var codeEl = document.getElementById(cronCopyBtn.dataset.copyCode);
                if (!codeEl) return;
                var text = codeEl.textContent;
                var done = function() {
                    var orig = cronCopyBtn.textContent;
                    cronCopyBtn.textContent = t('settings.cron_copied');
                    setTimeout(function() { cronCopyBtn.textContent = orig; }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, done);
                } else {
                    // Fallback: select + execCommand
                    var range = document.createRange();
                    range.selectNode(codeEl);
                    window.getSelection().removeAllRanges();
                    window.getSelection().addRange(range);
                    try { document.execCommand('copy'); done(); } catch (e) { done(); }
                    window.getSelection().removeAllRanges();
                }
            });
        });

        // Refresh whenever the backup tab is shown (lazy: only fetch when visible).
        var backupTab = document.getElementById('backupTab');
        var observer = new MutationObserver(function() {
            if (backupTab.style.display !== 'none') refresh();
        });
        observer.observe(backupTab, { attributes: true, attributeFilter: ['style'] });
        if (backupTab.style.display !== 'none') refresh();

        // Refresh after a manual create-site-backup so the new entry shows up.
        var createBtn = document.getElementById('createSiteBackupBtn');
        if (createBtn) {
            createBtn.addEventListener('click', function() { setTimeout(refresh, 3000); });
        }
    })();
