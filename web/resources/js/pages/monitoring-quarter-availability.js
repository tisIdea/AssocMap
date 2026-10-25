export function refreshQuarterAvailability({ form, occupiedPeriods, editingRecord, periodValues, selectedProject, status }) {
    if (editingRecord) { status.hidden = true; return true; }
    const select = form.elements.quarter_id;
    const year = Number(form.elements.year.value);
    const project = selectedProject();
    const ready = Boolean(project) && /^\d{4}$/.test(form.elements.year.value) && year >= 2000 && year <= 2100;
    const used = new Set(occupiedPeriods.filter(period => ready && String(period.project_id) === String(project.id)
        && Number(period.year) === year).map(period => String(period.quarter_id)));
    const options = Array.from(select.options).filter(option => option.value);
    options.forEach(option => {
        option.dataset.availableLabel ??= option.textContent;
        option.disabled = !ready || used.has(option.value);
        option.textContent = option.dataset.availableLabel + (ready && used.has(option.value) ? ' — already recorded' : '');
    });
    if (select.value && !options.some(option => option.value === select.value && !option.disabled)) {
        select.value = '';
        periodValues.quarter_id = '';
    }
    const available = options.filter(option => !option.disabled);
    const canCreate = ready && available.length > 0;
    select.disabled = !canCreate;
    select.options[0].textContent = !ready ? 'Select project and year first…' : !available.length ? 'No quarters available' : 'Select a quarter…';
    select.setCustomValidity(canCreate ? '' : 'Select a project and year with an available monitoring quarter.');
    form.querySelector('button[type="submit"]').disabled = !canCreate;
    status.hidden = false;
    status.textContent = !ready ? 'Select a project and monitoring year to see available quarters.'
        : !available.length ? 'No quarters remain available for this project and year. View or edit its existing records from the Monitoring dashboard.'
        : used.size ? 'Already recorded quarters cannot be selected. Choose an available quarter, or view existing records from the Monitoring dashboard.'
        : 'All quarters are available for this project and year.';
    return canCreate;
}
