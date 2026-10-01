import { $, $$, setFieldVisibility } from '../../core/helpers.js';

export function initReports() {
    const form = $('[data-report-form]');
    if (!form) return;
    function syncReport() {
        const form = $('[data-report-form]');
        const scope = $('[name=scope]:checked', form).value;
        $$('[data-report-scope]', form).forEach(container => setFieldVisibility(container, container.dataset.reportScope === scope));
        const type = $('[name=report_type]', form).value;
        const visibility = {
            quarter: ['Comprehensive Report', 'Monitoring Report'].includes(type),
            component: ['Comprehensive Report', 'Project Report', 'Training Report'].includes(type),
            status: ['Comprehensive Report', 'Project Report', 'Monitoring Report'].includes(type),
        };
        $$('[data-report-relevance]', form).forEach(container => setFieldVisibility(container, visibility[container.dataset.reportRelevance]));
    }


    form.addEventListener('change', syncReport);
    form.addEventListener('reset', () => setTimeout(syncReport, 0));
    syncReport();
}
