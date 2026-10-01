import { $, $$ } from './helpers.js';

export function initDialogs() {
    const openers = new WeakMap();
    document.addEventListener('click', event => {
        const button = event.target.closest('button, a');
        if (!button) return;
        if (button.matches('[data-close-dialog]')) button.closest('dialog')?.close();
        let id = button.dataset.dialog;
        if (button.matches('[data-area-add], [data-area-edit]')) {
            id = 'area-editor';
            const form = $('[data-area-form]');
            const editing = button.hasAttribute('data-area-edit');
            const record = editing ? JSON.parse(button.closest('[data-area-record]').dataset.areaRecord) : null;
            form.reset();
            form.dataset.mode = editing ? 'edit' : 'create';
            form.dataset.updateAction = record?.update_url || '';
            $('#area-editor-title').textContent = editing ? `Edit ${record.area_type}` : 'Add Area';
            $('select[name=area_type]', form).value = record?.area_type === 'Barangay' ? 'Barangay' : 'Municipality';
            $('[name=area_record_id]', form).value = record?.id || '';
            $$('[name=name]', form).forEach(input => { input.value = record?.name || ''; });
            $('[name=psgc_code]', form).value = record?.psgc_code || '';
            const parent = $('[name=municipality_id]', form);
            $$('[data-current-parent]', parent).forEach(option => option.remove());
            if (record?.municipality_id && !Array.from(parent.options).some(option => option.value === record.municipality_id)) {
                const option = new Option(record.municipality, record.municipality_id);
                option.dataset.currentParent = '';
                parent.add(option);
            }
            parent.value = record?.municipality_id || '';
            $$('[data-area-editor-detail]', form).forEach(element => {
                const defaults = { province: 'Cebu', region: 'Region VII', source: 'Manual', status: 'Active' };
                element.textContent = record ? (record[element.dataset.areaEditorDetail] ?? '—') : defaults[element.dataset.areaEditorDetail];
            });
            $('[data-area-validation]', form)?.setAttribute('hidden', '');
            form.dispatchEvent(new Event('change'));
        }
        if (button.matches('[data-area-view]')) {
            id = 'area-modal';
            const record = JSON.parse(button.closest('[data-area-record]').dataset.areaRecord);
            $$('[data-area-detail]', $('#area-modal')).forEach(element => {
                const value = record[element.dataset.areaDetail];
                element.textContent = value === null || value === undefined || value === '' ? '—' : value;
            });
            $('[data-area-detail=municipality]').closest('div').hidden = record.area_type !== 'Barangay';
            $('[data-area-province]').hidden = !record.province;
            $('[data-area-region]').hidden = !record.region;
        }
        if (button.matches('[data-confirm]')) {
            id = 'confirmation-modal';
            $('[data-confirm-message]').textContent = button.dataset.confirm;
            $('#confirmation-modal-title').textContent = button.dataset.confirmTitle || 'Review action';
            $('[data-confirm-action]').textContent = button.dataset.confirmLabel || 'Confirm';
            const form = $('[data-confirm-form]');
            const areaAction = button.hasAttribute('data-area-deactivate');
            form.dataset.areaAction = String(areaAction);
            form.action = areaAction ? JSON.parse(button.closest('[data-area-record]').dataset.areaRecord).deactivate_url : '';
            $('[data-confirm-action]').disabled = !areaAction;
            $('[data-confirm-placeholder]').hidden = areaAction;
        }
        const dialog = id && document.getElementById(id);
        if (!(dialog instanceof HTMLDialogElement)) return;
        openers.set(dialog, button);
        dialog.showModal();
    });
    $('[data-confirm-form]')?.addEventListener('submit', event => {
        if (event.currentTarget.dataset.areaAction !== 'true') event.preventDefault();
    });
    $$('dialog').forEach(dialog => {
        dialog.addEventListener('close', () => openers.get(dialog)?.focus());
        dialog.addEventListener('click', event => {
            const rect = dialog.getBoundingClientRect();
            if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
        });
    });
}
