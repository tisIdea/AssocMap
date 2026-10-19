import { initUserMenus } from './users.js';

export function initAssociationUi(root = document.querySelector('[data-association-ui]')) {
    if (!root || root.dataset.associationUiInitialized === 'true') return;
    root.dataset.associationUiInitialized = 'true';
    initUserMenus(root);
    const form = document.querySelector('[data-association-auto-open="true"]');
    if (!form || form.dataset.hasErrors === 'true') return;
    const dialog = form.closest('dialog');
    if (!dialog || dialog.open) return;
    dialog.addEventListener('close', () => root.querySelector('h1')?.focus());
    dialog.showModal();
}
