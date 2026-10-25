export function createMonitoringRow({ template, prefix, values = {} }) {
    const row = template.content.firstElementChild.cloneNode(true);
    const inputs = Array.from(row.querySelectorAll('[data-key]')).filter(input => input.closest('[data-kind]') === row);
    inputs.forEach(input => {
        const key = input.dataset.key;
        input.name = `${prefix}[${key}]`;
        input.id = 'monitoring-' + input.name.replace(/[^a-zA-Z0-9_-]/g, '-');
        row.querySelector(`[data-label="${key}"]`)?.setAttribute('for', input.id);
        input.value = values[key] ?? '';
        if (key === 'id') input.disabled = !values.id;
    });
    return row;
}
