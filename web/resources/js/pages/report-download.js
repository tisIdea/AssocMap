import { applyFormErrors, showFeedback, showOperationError } from '../core/feedback.js';

export function initReportDownloads(form) {
    if (!form || form.dataset.downloadInitialized === 'true') return;
    form.dataset.downloadInitialized = 'true';
    let pending = false;
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (pending) return;
        const button = event.submitter;
        if (!button?.formAction || button.disabled) return;
        const url = new URL(button.formAction, window.location.href);
        if (url.origin !== window.location.origin) return;
        const format = url.pathname.split('/').pop();
        if (!['pdf', 'xlsx'].includes(format)) return;
        const buttons = [...form.querySelectorAll('button')].map(node => [node, node.disabled]);
        pending = true; form.dataset.downloadPending = 'true'; form.setAttribute('aria-busy', 'true');
        buttons.forEach(([node]) => { node.disabled = true; });
        try {
            const response = await fetch(url.href, { method: 'POST', body: new FormData(form), credentials: 'same-origin',
                redirect: 'error', headers: { Accept: 'application/json' } });
            if (!response.ok) {
                if (response.status === 422 && (response.headers.get('content-type') ?? '').includes('application/json')) {
                    const body = await response.json().catch(() => null);
                    applyFormErrors(form.closest('[data-report-workspace]')?.querySelector('[data-report-form]'), body?.errors);
                }
                const error = new Error('Report unavailable'); error.status = response.status; throw error;
            }
            const expected = format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            if ((response.headers.get('content-type') ?? '').split(';')[0].trim().toLowerCase() !== expected) {
                const error = new Error('Unexpected report response'); error.status = 503; throw error;
            }
            const blob = await response.blob();
            if (!blob.size) { const error = new Error('Empty report response'); error.status = 503; throw error; }
            const disposition = response.headers.get('content-disposition') ?? '';
            const suppliedName = disposition.match(/filename="?([^";]+)"?/i)?.[1];
            const filename = suppliedName && /^assocmap-[a-z0-9_-]+\.(pdf|xlsx)$/i.test(suppliedName) && suppliedName.endsWith('.' + format)
                ? suppliedName : `assocmap-report.${format}`;
            const objectUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = objectUrl; link.download = filename; link.hidden = true;
            try { document.body.append(link); link.click(); }
            finally { link.remove(); setTimeout(() => URL.revokeObjectURL(objectUrl), 1000); }
            void showFeedback({ type: 'success', title: 'Report Generated',
                message: 'The report was generated and the download was requested. Check your browser downloads.', returnFocus: button });
        } catch (error) {
            void showOperationError(error, { context: 'Report', returnFocus: button });
        } finally {
            pending = false; delete form.dataset.downloadPending; form.removeAttribute('aria-busy');
            buttons.forEach(([node, disabled]) => { node.disabled = disabled || form.dataset.exportReady === 'false'; });
        }
    });
}
