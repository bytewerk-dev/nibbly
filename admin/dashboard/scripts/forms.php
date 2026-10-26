<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // ---- Multilingual form texts --------------------------------------------
    // Label, description, success message, field label/placeholder/help and option
    // labels are stored as a string or a {lang: text} map (includes/forms.php).
    // The editor keeps every text as a map and shows the language picked in
    // #formTextLang; with a single site language it saves plain strings.
    const FORM_TEXT_LANGS = <?php echo json_encode(array_keys($siteLanguages ?? [])); ?>;
    const FORM_TEXT_PRIMARY = <?php echo json_encode(defined('SITE_LANG_DEFAULT') ? SITE_LANG_DEFAULT : 'en'); ?>;
    let formTextLang = FORM_TEXT_PRIMARY;
    let formTexts = { label: {}, description: {}, successText: {} };

    function toTextMap(value) {
        if (value && typeof value === 'object' && !Array.isArray(value)) return Object.assign({}, value);
        const map = {};
        if (value !== undefined && value !== null && String(value) !== '') map[FORM_TEXT_PRIMARY] = String(value);
        return map;
    }

    function textFallback(map) {
        return (map && (map[FORM_TEXT_PRIMARY] || Object.values(map).find(text => String(text || '').trim() !== ''))) || '';
    }

    /** Map → what is saved: a map of the site languages, or a plain string on one-language sites. */
    function fromTextMap(map) {
        const clean = {};
        (FORM_TEXT_LANGS.length ? FORM_TEXT_LANGS : [FORM_TEXT_PRIMARY]).forEach(lang => {
            if (map && map[lang] !== undefined && String(map[lang]).trim() !== '') clean[lang] = String(map[lang]);
        });
        if (FORM_TEXT_LANGS.length <= 1) return clean[FORM_TEXT_PRIMARY] || textFallback(map);
        return Object.keys(clean).length ? clean : '';
    }

    /** Show a map in an input for the current language; other languages hint the primary text. */
    function showText(input, map) {
        if (!input) return;
        input.value = (map && map[formTextLang]) || '';
        if (input.dataset.basePlaceholder === undefined) input.dataset.basePlaceholder = input.getAttribute('placeholder') || '';
        input.placeholder = formTextLang !== FORM_TEXT_PRIMARY ? textFallback(map) : input.dataset.basePlaceholder;
    }

    function commitText(input, map) {
        if (!input || !map) return;
        const value = input.value;
        if (value.trim() === '') delete map[formTextLang];
        else map[formTextLang] = value;
    }

    function optionLine(value, label) {
        return value === label ? value : `${value}|${label}`;
    }

    /** Options textarea for the current language: values from the primary language, labels translated. */
    function showRowOptions(tr) {
        const textarea = tr.querySelector('[data-field-prop="options"]');
        if (!textarea) return;
        textarea.value = tr._options.map(option => optionLine(option.value, option.label[formTextLang] || textFallback(option.label) || option.value)).join('\n');
    }

    function commitRowOptions(tr) {
        const parsed = parseFormOptions(tr.querySelector('[data-field-prop="options"]').value);
        if (formTextLang === FORM_TEXT_PRIMARY) {
            // The primary language defines the options; translations follow by value
            tr._options = parsed.map(option => {
                const previous = tr._options.find(existing => existing.value === option.value);
                const label = previous ? Object.assign({}, previous.label) : {};
                label[FORM_TEXT_PRIMARY] = option.label;
                return { value: option.value, label };
            });
            return;
        }
        tr._options.forEach(option => {
            const match = parsed.find(item => item.value === option.value);
            const primary = textFallback(option.label);
            if (match && match.label && match.label !== primary) option.label[formTextLang] = match.label;
            else delete option.label[formTextLang];
        });
    }

    function showFormTexts() {
        showText(document.getElementById('formEditorLabel'), formTexts.label);
        showText(document.getElementById('formEditorDescription'), formTexts.description);
        showText(document.getElementById('formEditorSuccess'), formTexts.successText);
        document.querySelectorAll('#formFieldsBody tr').forEach(tr => {
            showText(tr.querySelector('[data-field-prop="label"]'), tr._texts.label);
            showText(tr.querySelector('[data-field-prop="placeholder"]'), tr._texts.placeholder);
            showRowOptions(tr);
        });
    }

    function commitFormTexts() {
        commitText(document.getElementById('formEditorLabel'), formTexts.label);
        commitText(document.getElementById('formEditorDescription'), formTexts.description);
        commitText(document.getElementById('formEditorSuccess'), formTexts.successText);
        document.querySelectorAll('#formFieldsBody tr').forEach(tr => {
            commitText(tr.querySelector('[data-field-prop="label"]'), tr._texts.label);
            commitText(tr.querySelector('[data-field-prop="placeholder"]'), tr._texts.placeholder);
            commitRowOptions(tr);
        });
    }

    document.querySelectorAll('input[name="formTextLang"]').forEach(radio => {
        radio.addEventListener('change', function() {
            if (!this.checked) return;
            commitFormTexts();
            formTextLang = this.value;
            showFormTexts();
        });
    });

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
        formTexts = {
            label: toTextMap(form.label),
            description: toTextMap(form.description),
            successText: toTextMap((form.submit && form.submit.successText) || t('forms.default_success'))
        };
        document.getElementById('formEditorEnabled').checked = form.enabled !== false;
        document.getElementById('formEditorStore').checked = !form.submit || form.submit.store !== false;
        document.getElementById('formEditorEmail').checked = !form.submit || form.submit.email !== false;
        document.getElementById('formEditorSubject').value = (form.submit && form.submit.subject) || '{form}: {name}';
        renderFormFields(form.fields || []);
        showFormTexts();
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
        // Translatable texts live on the row as maps; the inputs show formTextLang
        tr._texts = { label: toTextMap(field.label), placeholder: toTextMap(field.placeholder), help: toTextMap(field.help) };
        tr._options = (field.options || []).map(option => typeof option === 'string'
            ? { value: option, label: toTextMap(option) }
            : { value: String(option.value ?? textFallback(toTextMap(option.label))), label: toTextMap(option.label) });
        tr.innerHTML = `
            <td data-label="${escapeHtml(t('forms.type'))}"><select data-field-prop="type">
                ${['text', 'email', 'tel', 'textarea', 'select', 'radio', 'checkbox', 'date', 'time', 'heading', 'note', 'hidden'].map(type => `<option value="${type}"${field.type === type ? ' selected' : ''}>${type}</option>`).join('')}
            </select></td>
            <td data-label="${escapeHtml(t('forms.key'))}"><input type="text" data-field-prop="key" value="${escapeHtml(field.key || '')}"></td>
            <td data-label="${escapeHtml(t('forms.label'))}"><input type="text" data-field-prop="label"></td>
            <td data-label="${escapeHtml(t('forms.placeholder'))}"><input type="text" data-field-prop="placeholder"></td>
            <td data-label="${escapeHtml(t('forms.width'))}"><select data-field-prop="width">
                ${[3, 4, 6, 8, 12].map(width => `<option value="${width}"${Number(field.width || 12) === width ? ' selected' : ''}>${width}/12</option>`).join('')}
            </select></td>
            <td class="forms-field-check" data-label="${escapeHtml(t('forms.required'))}"><input type="checkbox" data-field-prop="required"${field.required ? ' checked' : ''}></td>
            <td class="forms-field-options" data-label="${escapeHtml(t('forms.options'))}"><textarea data-field-prop="options" rows="2"></textarea></td>
            <td class="users-table__actions"><button type="button" class="btn-icon btn-icon--danger" title="${escapeHtml(t('btn.delete'))}">${icon('trash', 14, '2')}</button></td>
        `;
        tr.querySelector('.btn-icon--danger').addEventListener('click', () => tr.remove());
        showText(tr.querySelector('[data-field-prop="label"]'), tr._texts.label);
        showText(tr.querySelector('[data-field-prop="placeholder"]'), tr._texts.placeholder);
        showRowOptions(tr);
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
        commitFormTexts();
        const fields = Array.from(document.querySelectorAll('#formFieldsBody tr')).map(row => {
            const get = prop => row.querySelector(`[data-field-prop="${prop}"]`);
            return {
                type: get('type').value,
                key: get('key').value,
                label: fromTextMap(row._texts.label),
                placeholder: fromTextMap(row._texts.placeholder),
                help: fromTextMap(row._texts.help),
                required: get('required').checked,
                width: Number(get('width').value) || 12,
                options: row._options.map(option => ({ value: option.value, label: fromTextMap(option.label) || option.value }))
            };
        });
        return {
            id: document.getElementById('formEditorId').value,
            label: fromTextMap(formTexts.label),
            description: fromTextMap(formTexts.description),
            enabled: document.getElementById('formEditorEnabled').checked,
            submit: {
                store: document.getElementById('formEditorStore').checked,
                email: document.getElementById('formEditorEmail').checked,
                subject: document.getElementById('formEditorSubject').value,
                successText: fromTextMap(formTexts.successText)
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
