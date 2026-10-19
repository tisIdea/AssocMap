export function initProjectClosure() {
    document.querySelectorAll('[data-project-finalization-form]').forEach(form => {
        if (form.dataset.finalizationInitialized === 'true') return;
        form.dataset.finalizationInitialized = 'true';
        const dialog = form.closest('dialog');
        const remarks = form.elements.namedItem('closure_remarks');
        const confirmation = form.elements.namedItem(form.dataset.confirmationField);
        const submit = form.querySelector('[data-project-finalization-submit]');
        const status = form.querySelector('[data-project-finalization-status]');
        const closers = dialog.querySelectorAll('[data-close-dialog]');
        const opener = document.querySelector('[data-project-finalization-open="' + dialog.id + '"]');
        const label = submit.textContent;
        let processing = false;
        dialog.setAttribute('aria-describedby', dialog.id + '-warning');
        const valid = () => /[^\p{Z}\p{C}\s]/u.test(remarks.value)
            && Array.from(remarks.value.trim()).length <= 2000
            && confirmation.value === form.dataset.confirmationWord;
        const sync = () => { submit.disabled = processing || !valid(); };
        form.addEventListener('input', sync);
        opener?.addEventListener('click', () => requestAnimationFrame(() => remarks.focus()));
        dialog.addEventListener('cancel', event => { if (processing) event.preventDefault(); });
        dialog.addEventListener('click', event => {
            if (processing && event.target === dialog) { event.preventDefault(); event.stopImmediatePropagation(); }
        }, true);
        dialog.addEventListener('close', () => {
            if (!processing) { confirmation.value = ''; opener?.focus(); }
            sync();
        });
        form.addEventListener('submit', event => {
            if (processing || !valid() || !form.checkValidity()) { event.preventDefault(); return; }
            processing = true;
            form.setAttribute('aria-busy', 'true');
            submit.textContent = 'Finalizing project…';
            status.textContent = 'Recording finalization. Please wait.';
            closers.forEach(button => { button.disabled = true; });
            sync();
        });
        window.addEventListener('pageshow', () => {
            processing = false;
            form.removeAttribute('aria-busy');
            submit.textContent = label;
            confirmation.value = '';
            status.textContent = '';
            closers.forEach(button => { button.disabled = false; });
            sync();
        });
        sync();
        if (form.dataset.openFinalization === 'true') { dialog.dataset.feedbackRestore = 'true'; dialog.feedbackFocusTarget = remarks; }
    });
}
