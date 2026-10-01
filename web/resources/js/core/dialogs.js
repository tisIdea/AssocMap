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
            $('#area-editor-title').textContent = button.hasAttribute('data-area-edit') ? 'Edit Area' : 'Add Area';
            $('[data-area-form]').reset();
        }
        if (button.matches('[data-area-view]')) id = 'area-modal';
        if (button.matches('[data-confirm]')) {
            id = 'confirmation-modal';
            $('[data-confirm-message]').textContent = button.dataset.confirm;
            $('#confirmation-modal-title').textContent = button.dataset.confirmTitle || 'Review action';
            $('[data-confirm-action]').textContent = button.dataset.confirmLabel || 'Confirm';
        }
        const dialog = id && document.getElementById(id);
        if (!(dialog instanceof HTMLDialogElement)) return;
        openers.set(dialog, button);
        dialog.showModal();
    });
    $$('dialog').forEach(dialog => {
        dialog.addEventListener('close', () => openers.get(dialog)?.focus());
        dialog.addEventListener('click', event => {
            const rect = dialog.getBoundingClientRect();
            if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
        });
    });
}
