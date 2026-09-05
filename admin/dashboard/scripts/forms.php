<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    async function loadFormsAdmin() {
        try {
            const response = await fetch('api.php?action=list-forms');
            const result = await response.json();
            if (!result.success) {
                showToast(result.message || t('forms.load_error'), 'error');
                return;
            }
            formsAdminData = result.data || [];
            renderFormsAdminList();
            if (formsAdminData.length && !currentFormEditorId) {
                await loadFormEditor(formsAdminData[0].id);
            } else if (!formsAdminData.length && !currentFormEditorId) {
                populateFormEditor(defaultFormDefinition('kontakt'));
            }
        } catch (error) {
            showToast(t('toast.error_loading', {message: error.message}), 'error');
        }
    }

    function renderFormsAdminList() {
        const select = document.getElementById('formsAdminSelect');
        const meta = document.getElementById('formsAdminMeta');
        if (!select) return;
        const current = currentFormEditorId;
        select.innerHTML = '';
        if (!formsAdminData.length) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = t('forms.empty');
            select.appendChild(option);
            select.disabled = true;
            if (meta) meta.textContent = '';
            return;
        }
        select.disabled = false;
        formsAdminData.forEach(form => {
            const option = document.createElement('option');
            option.value = form.id;
            option.textContent = `${form.label || form.id} (${form.id})`;
            select.appendChild(option);
        });
        if (formsAdminData.some(form => form.id === current)) {
            select.value = current;
        }
        const selected = formsAdminData.find(form => form.id === select.value);
        if (meta) {
            meta.textContent = selected
                ? `${selected.id} · ${t('forms.field_count', {count: selected.fieldCount || 0})}`
                : '';
        }
    }

    async function loadFormEditor(formId) {
        try {
            const response = await fetch('api.php?action=load-form&form_id=' + encodeURIComponent(formId));
            const result = await response.json();
            if (!result.success) {
                showToast(result.message || t('forms.load_error'), 'error');
                return;
            }
            populateFormEditor(result.data);
        } catch (error) {
            showToast(t('toast.error_loading', {message: error.message}), 'error');
        }
    }

    function populateFormEditor(form) {
        currentFormEditorId = form.id || '';
        document.getElementById('formEditorId').value = form.id || '';
        document.getElementById('formEditorLabel').value = form.label || '';
        document.getElementById('formEditorDescription').value = form.description || '';
        document.getElementById('formEditorEnabled').checked = form.enabled !== false;
        document.getElementById('formEditorStore').checked = !form.submit || form.submit.store !== false;
        document.getElementById('formEditorEmail').checked = !form.submit || form.submit.email !== false;
        document.getElementById('formEditorSubject').value = (form.submit && form.submit.subject) || '{form}: {name}';
        document.getElementById('formEditorSuccess').value = (form.submit && form.submit.successText) || t('forms.default_success');
        renderFormFields(form.fields || []);
        renderFormsAdminList();
    }

    function renderFormFields(fields) {
        const tbody = document.getElementById('formFieldsBody');
        if (!tbody) return;
        tbody.innerHTML = '';
        fields.forEach(field => tbody.appendChild(createFormFieldRow(field)));
    }

    function createFormFieldRow(field) {
        const tr = document.createElement('tr');
        tr.className = 'forms-field-row';
        const optionsText = (field.options || []).map(option => {
            if (typeof option === 'string') return option;
            return option.value === option.label ? option.value : `${option.value}|${option.label}`;
        }).join('\n');
        tr.innerHTML = `
            <td data-label="${escapeHtml(t('forms.type'))}"><select data-field-prop="type">
                ${['text', 'email', 'tel', 'textarea', 'select', 'radio', 'checkbox', 'date', 'time', 'heading', 'note', 'hidden'].map(type => `<option value="${type}"${field.type === type ? ' selected' : ''}>${type}</option>`).join('')}
            </select></td>
            <td data-label="${escapeHtml(t('forms.key'))}"><input type="text" data-field-prop="key" value="${escapeHtml(field.key || '')}"></td>
            <td data-label="${escapeHtml(t('forms.label'))}"><input type="text" data-field-prop="label" value="${escapeHtml(field.label || '')}"></td>
            <td data-label="${escapeHtml(t('forms.placeholder'))}"><input type="text" data-field-prop="placeholder" value="${escapeHtml(field.placeholder || '')}"></td>
            <td data-label="${escapeHtml(t('forms.width'))}"><select data-field-prop="width">
                ${[3, 4, 6, 8, 12].map(width => `<option value="${width}"${Number(field.width || 12) === width ? ' selected' : ''}>${width}/12</option>`).join('')}
            </select></td>
            <td class="forms-field-check" data-label="${escapeHtml(t('forms.required'))}"><input type="checkbox" data-field-prop="required"${field.required ? ' checked' : ''}></td>
            <td data-label="${escapeHtml(t('forms.options'))}"><textarea data-field-prop="options" rows="2">${escapeHtml(optionsText)}</textarea></td>
            <td class="users-table__actions"><button type="button" class="btn-icon btn-icon--danger" title="${escapeHtml(t('btn.delete'))}">${icon('trash', 14, '2')}</button></td>
        `;
        tr.querySelector('.btn-icon--danger').addEventListener('click', () => tr.remove());
        return tr;
    }

    function parseFormOptions(text) {
        return String(text || '').split(/\r?\n/)
            .map(line => line.trim())
            .filter(Boolean)
            .map(line => {
                const parts = line.split('|');
                const value = (parts[0] || '').trim();
                const label = (parts[1] || parts[0] || '').trim();
                return { value, label };
            });
    }

    function collectFormEditor() {
        const fields = Array.from(document.querySelectorAll('#formFieldsBody tr')).map(row => {
            const get = prop => row.querySelector(`[data-field-prop="${prop}"]`);
            return {
                type: get('type').value,
                key: get('key').value,
                label: get('label').value,
                placeholder: get('placeholder').value,
                required: get('required').checked,
                width: Number(get('width').value) || 12,
                options: parseFormOptions(get('options').value)
            };
        });
        return {
            id: document.getElementById('formEditorId').value,
            label: document.getElementById('formEditorLabel').value,
            description: document.getElementById('formEditorDescription').value,
            enabled: document.getElementById('formEditorEnabled').checked,
            submit: {
                store: document.getElementById('formEditorStore').checked,
                email: document.getElementById('formEditorEmail').checked,
                subject: document.getElementById('formEditorSubject').value,
                successText: document.getElementById('formEditorSuccess').value
            },
            fields
        };
    }

    async function saveFormEditor(event) {
        event.preventDefault();
        const resultBox = document.getElementById('formsAdminResult');
        try {
            const formData = new FormData();
            formData.append('action', 'save-form');
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('form', JSON.stringify(collectFormEditor()));
            const response = await fetch('api.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (!result.success) {
                showToast(result.message || t('forms.save_error'), 'error');
                return;
            }
            populateFormEditor(result.data);
            await loadFormsAdmin();
            if (resultBox) {
                resultBox.className = 'settings-test-result settings-test-result--success';
                resultBox.textContent = t('forms.saved');
                resultBox.style.display = '';
            }
            showToast(t('forms.saved'), 'success');
        } catch (error) {
            showToast(t('toast.error_generic', {message: error.message}), 'error');
        }
    }

    document.getElementById('formsAdminForm')?.addEventListener('submit', saveFormEditor);
    document.getElementById('formsAdminSelect')?.addEventListener('change', function() {
        if (this.value) loadFormEditor(this.value);
    });
    document.getElementById('addFormFieldBtn')?.addEventListener('click', function() {
        document.getElementById('formFieldsBody')?.appendChild(createFormFieldRow({
            type: 'text',
            key: 'field_' + (document.querySelectorAll('#formFieldsBody tr').length + 1),
            label: t('forms.new_field'),
            placeholder: '',
            required: false,
            width: 12,
            options: []
        }));
    });
    document.getElementById('newFormBtn')?.addEventListener('click', function() {
        currentFormEditorId = '';
        populateFormEditor(defaultFormDefinition('formular-' + (formsAdminData.length + 1)));
    });

    // Settings sub-tabs
    function getActiveSettingsTab() {
        var active = document.querySelector('.settings-tab-btn.active');
        return active ? active.getAttribute('data-settings-tab') : 'branding';
    }
