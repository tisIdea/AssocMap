<script type="module">
    function initVisitReport(form) {
        if (!form || form.dataset.visitInitialized === 'true') return;
        form.dataset.visitInitialized = 'true';
        const officer = form.elements.conducted_by_user_id;
        const name = form.elements.conducted_by;
        const saved = JSON.parse(form.dataset.savedConductor);
        const error = form.querySelector('[data-visit-conductor-error]');
        const submit = form.querySelector('[type="submit"]');
        const status = form.querySelector('[data-visit-submit-status]');
        let submitting = false;
        function validateConductor() {
            const unchanged = saved && officer.value === (saved.id ?? '') && name.value.trim() === (saved.name ?? '');
            const invalid = !unchanged && Boolean(officer.value) === Boolean(name.value.trim());
            const message = invalid ? 'Select the assigned Field Officer or enter another person’s name, using exactly one option.' : '';
            name.setCustomValidity(message);
            name.setAttribute('aria-invalid', String(invalid));
            officer.setAttribute('aria-invalid', String(invalid));
            error.textContent = message;
            error.hidden = !invalid;
        }
        [officer, name].forEach(input => {
            input.addEventListener('input', validateConductor);
            input.addEventListener('change', validateConductor);
        });
        form.addEventListener('submit', event => {
            if (submitting) { event.preventDefault(); return; }
            validateConductor();
            if (!form.checkValidity()) { event.preventDefault(); form.reportValidity(); return; }
            submitting = true;
            submit.disabled = true;
            form.setAttribute('aria-busy', 'true');
            status.textContent = 'Saving Visit Report. Please wait.';
        });
        window.addEventListener('pageshow', () => {
            submitting = false;
            submit.disabled = false;
            form.removeAttribute('aria-busy');
            status.textContent = '';
        });
        const historicalToggle = form.querySelector('#visit-change-historical-date');
        if (historicalToggle) historicalToggle.addEventListener('change', () => {
            const date = form.elements.monitoring_date;
            date.readOnly = !historicalToggle.checked;
            if (date.readOnly) date.value = form.dataset.historicalDate;
            else date.focus();
        });
        validateConductor();
        form.querySelector('[data-visit-validation]')?.focus();
    }
    initVisitReport(document.querySelector('[data-visit-report-form]'));
</script>
