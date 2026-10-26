import { initReportDownloads } from '../pages/report-download.js';

export function initContextualReports() {
    document.querySelectorAll('[data-contextual-report]').forEach(root => {
        if (root.dataset.contextualReportInitialized === 'true') return;
        root.dataset.contextualReportInitialized = 'true';
        initReportDownloads(root.querySelector('[data-contextual-report-export]'));
        const menu = root.querySelector('details');
        menu?.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            menu.open = false;
            menu.querySelector('summary').focus();
        });
    });
}
