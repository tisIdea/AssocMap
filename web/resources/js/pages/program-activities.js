import { initUserMenus } from './admin/users.js';

export function initProgramActivities(root = document.querySelector('[data-program-activities]')) {
    if (!root || root.dataset.programActivitiesInitialized === 'true') return;
    root.dataset.programActivitiesInitialized = 'true';
    initUserMenus(root);
    const dialog = document.getElementById('program-activity-editor');
    if (!dialog) return;
    dialog.addEventListener('close', () => root.querySelector('h1')?.focus());
    if (dialog.querySelector('[data-program-activity-errors]')) {
        dialog.dataset.feedbackRestore = 'true';
        return;
    }
    if (dialog.dataset.feedbackRestore === 'true') return;
    if (!dialog.open && !document.querySelector('dialog[open]')) dialog.showModal();
}
