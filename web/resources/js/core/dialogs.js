import { $, $$ } from './helpers.js';

export function initDialogs() {
    const openers = new WeakMap();
    const navigationDialog = $('#navigation-confirmation-modal');
    let navigationUrl = null;
    if (navigationDialog) {
        navigationDialog.setAttribute('aria-describedby', 'navigation-confirmation-message');
        document.addEventListener('assocmap:confirm-navigation', event => {
            const { link, title, message, label } = event.detail;
            const url = new URL(link.href, window.location.origin);
            if (url.origin !== window.location.origin || navigationDialog.open) return;
            navigationUrl = url.href;
            $('#navigation-confirmation-modal-title').textContent = title;
            $('[data-navigation-message]', navigationDialog).textContent = message;
            $('[data-navigation-confirm]', navigationDialog).textContent = label;
            openers.set(navigationDialog, link);
            navigationDialog.showModal();
            $('[data-navigation-cancel]', navigationDialog).focus();
        });
        $('[data-navigation-confirm]', navigationDialog).addEventListener('click', () => {
            if (navigationUrl) window.location.assign(navigationUrl);
        });
        navigationDialog.addEventListener('close', () => { navigationUrl = null; });
    }
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
            $('select[name=area_type]', form).value = record
                ? (record.area_type === 'Barangay' ? 'Barangay' : 'Municipality')
                : (button.dataset.areaCreateType || 'Municipality');
            $('[name=area_record_id]', form).value = record?.id || '';
            $$('[name=name]', form).forEach(input => { input.value = record?.name || ''; });
            $('[name=psgc_code]', form).value = record?.psgc_code || '';
            $('[data-specific-municipality]', form).value = editing ? '' : (button.dataset.areaParent || '');
            $('[name=barangay_id]', form).value = editing ? '' : (button.dataset.areaBarangay || '');
            $('[name=address]', form).value = '';
            const parent = $('[name=municipality_id]', form);
            $$('[data-current-parent]', parent).forEach(option => option.remove());
            if (record?.municipality_id && !Array.from(parent.options).some(option => option.value === record.municipality_id)) {
                const option = new Option(record.municipality, record.municipality_id);
                option.dataset.currentParent = '';
                parent.add(option);
            }
            parent.value = record?.municipality_id || button.dataset.areaParent || '';
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
            $('[data-area-detail=municipality]').closest('div').hidden = !['Barangay', 'Specific Area'].includes(record.area_type);
            $('[data-area-detail=barangay]').closest('div').hidden = record.area_type !== 'Specific Area';
            $('[data-area-detail=psgc_code]').closest('div').hidden = record.area_type === 'Specific Area';
            $('[data-area-detail=name]').previousElementSibling.textContent = record.area_type === 'Specific Area' ? 'Area / Address' : 'Area Name';
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
            const associationUrl = button.hasAttribute('data-association-unarchive') ? button.dataset.unarchiveUrl : button.dataset.archiveUrl;
            const associationAction = button.matches('[data-association-archive], [data-association-unarchive]') && Boolean(associationUrl);
            const memberUrl = button.hasAttribute('data-member-unarchive') ? button.dataset.unarchiveUrl : button.dataset.archiveUrl;
            const memberAction = button.matches('[data-member-archive], [data-member-unarchive]') && Boolean(memberUrl);
            form.dataset.areaAction = String(areaAction);
            form.dataset.associationAction = String(associationAction);
            form.dataset.memberAction = String(memberAction);
            form.action = areaAction ? JSON.parse(button.closest('[data-area-record]').dataset.areaRecord).deactivate_url
                : (associationAction ? associationUrl : (memberAction ? memberUrl : ''));
            $('[data-confirm-action]').disabled = !(areaAction || associationAction || memberAction);
            $('[data-confirm-placeholder]').hidden = areaAction || associationAction || memberAction;
        }
        const dialog = id && document.getElementById(id);
        if (!(dialog instanceof HTMLDialogElement)) return;
        openers.set(dialog, button);
        dialog.showModal();
    });
    $('[data-confirm-form]')?.addEventListener('submit', event => {
        if (event.currentTarget.dataset.areaAction !== 'true' && event.currentTarget.dataset.associationAction !== 'true' && event.currentTarget.dataset.memberAction !== 'true') event.preventDefault();
    });
    $$('dialog').forEach(dialog => {
        if (dialog.hasAttribute('data-global-feedback')) return;
        dialog.addEventListener('close', () => openers.get(dialog)?.focus());
        dialog.addEventListener('click', event => {
            const rect = dialog.getBoundingClientRect();
            if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
        });
    });
}
