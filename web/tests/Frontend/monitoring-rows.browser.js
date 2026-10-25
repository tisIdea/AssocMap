export async function checkMonitoringRows({ form, state, check }) {
    const rows = kind => Array.from(document.getElementById('monitoring-' + kind).children);
    const field = (row, key) => row.querySelector(`[data-key="${key}"]`);
    const change = (input, value) => {
        input.value = value;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const tab = name => form.querySelector(`[data-monitoring-tab="${name}"]`).click();
    const add = kind => {
        tab(kind);
        form.querySelector(`[data-add-root="${kind}"]`).click();
        return rows(kind).at(-1);
    };
    const remove = async (row, confirm) => {
        const button = row.querySelector('[data-monitoring-remove-row]');
        button.click();
        const dialog = document.getElementById('monitoring-removal-modal');
        check(dialog.open && document.activeElement === dialog.querySelector('[data-removal-cancel]'), 'removal opens a labelled dialog with Cancel focused');
        const closed = new Promise(resolve => dialog.addEventListener('close', resolve, { once: true }));
        dialog.querySelector(confirm ? '[data-removal-confirm]' : '[data-removal-cancel]').click();
        await closed;
        check(!dialog.open && (confirm ? !row.isConnected : row.isConnected && document.activeElement === button), 'removal confirmation or cancellation preserves the expected row and focus', {
            kind: row.dataset.kind, confirm, connected: row.isConnected, focus: document.activeElement?.outerHTML,
        });
    };
    const snapshot = () => Array.from(new FormData(form), ([name, value]) => [name, name === '_token' ? '[csrf-present]' : value]);
    const assertFields = row => {
        const own = Array.from(row.querySelectorAll('[data-key]')).filter(input => input.closest('[data-kind]') === row);
        check(own.every(input => input.name === `${row.dataset.prefix}[${input.dataset.key}]`
            && input.id === 'monitoring-' + input.name.replace(/[^a-zA-Z0-9_-]/g, '-')), 'row fields keep their scoped names and unique IDs');
        check(own.every(input => !row.querySelector(`[data-label="${input.dataset.key}"]`)
            || row.querySelector(`[data-label="${input.dataset.key}"]`).htmlFor === input.id), 'row labels target their inputs');
    };

    if (state === 'create') {
        check(rows('production').length === 0 && rows('materials').length === 0, 'creation starts without detail rows');
        change(form.elements.year, '2026');
        change(form.elements.quarter_id, '22');
        const first = add('production');
        const second = add('production');
        check(first.dataset.prefix === 'production[0]' && second.dataset.prefix === 'production[1]', 'new Production rows retain insertion order and counters');
        check(second.contains(document.activeElement), 'new Production row receives focus');
        assertFields(first);
        check(field(first, 'id').disabled, 'unsaved row ID is excluded from the payload');
        change(field(first, 'project_product_id'), '66666666-6666-4666-8666-666666666666');
        change(field(second, 'project_product_id'), '66666666-6666-4666-8666-666666666666');
        check(field(second, 'project_product_id').validity.customError, 'duplicate product validation is retained');
        change(field(second, 'project_product_id'), '99999999-0000-4000-8000-000000000001');
        check(!field(second, 'project_product_id').validity.customError && field(first, 'unit').value === 'kg', 'eligible replacement clears duplicate validation and retains product unit');
        field(second, 'remarks').value = 'Keep this Production draft';
        const monthly = Array.from(first.querySelector('[data-monthly-container]').children);
        check(monthly.length === 3 && monthly.map(row => field(row, 'month').value).join(',') === '4,5,6', 'quarter creates three ordered monthly rows');
        check(monthly.every((row, index) => field(row, 'month').name === `production[0][monthly_details][${index}][month]`
            && field(row, 'id').disabled), 'monthly names and unsaved IDs retain their payload contract');
        check(monthly.every(row => row.querySelector('[data-label="total_quantity"]').htmlFor === field(row, 'total_quantity').id
            && field(row, 'total_quantity').getAttribute('aria-describedby') === row.querySelector('[data-month-unit]').id), 'monthly labels and unit descriptions remain associated');

        tab('income');
        const group = first.incomeGroup;
        const frequency = group.querySelector('[data-income-frequency]');
        change(frequency, 'quarterly');
        const income = group.querySelector('[data-kind="income"]');
        assertFields(income);
        field(income, 'gross_income').value = '12.34';
        field(income, 'remarks').value = '<img src=x onerror=alert(1)> draft';
        check(group.querySelector('[data-add-income]').disabled && field(income, 'month').disabled, 'quarterly income limits and disabled month remain unchanged');
        const beforeCancel = snapshot();
        await remove(income, false);
        check(JSON.stringify(snapshot()) === JSON.stringify(beforeCancel), 'cancelled removal preserves every FormData entry');
        await remove(income, true);
        check(group.querySelector('[data-add-income]') === document.activeElement, 'income removal restores Add Income focus');
        change(frequency, 'monthly');
        const monthlyIncome = group.querySelector('[data-kind="income"]');
        change(field(monthlyIncome, 'month'), '4');
        group.querySelector('[data-add-income]').click();
        const nextIncome = Array.from(group.querySelectorAll('[data-kind="income"]')).at(-1);
        check(monthlyIncome.dataset.prefix === 'production[0][income][1]' && nextIncome.dataset.prefix === 'production[0][income][2]', 'income row indices are not reused after removal');
        check(document.activeElement === field(nextIncome, 'month'), 'new monthly Income focuses its month');
        change(field(nextIncome, 'month'), '4');
        check(field(nextIncome, 'month').validity.customError, 'duplicate income month remains invalid');
        change(field(nextIncome, 'month'), '5');
        check(!field(nextIncome, 'month').validity.customError, 'eligible month clears income validation');
        field(nextIncome, 'gross_income').value = '45.67';
        tab('production');
        await remove(first, false);
        check(first.incomeGroup.isConnected && field(nextIncome, 'gross_income').value === '45.67', 'cancelling Production removal retains linked Income drafts');
        await remove(first, true);
        check(!group.isConnected && rows('production')[0] === second && second.dataset.prefix === 'production[1]'
            && field(second, 'remarks').value === 'Keep this Production draft', 'confirmed Production removal removes linked Income without renumbering or clearing another row');
        check(document.activeElement === form.querySelector('[data-add-root="production"]'), 'Production removal restores Add Production focus');
        const third = add('production');
        check(third.dataset.prefix === 'production[2]', 'Production counter does not reuse removed indices');
        change(third.querySelector('[data-resource-mode]'), 'new');
        const name = third.querySelector('[data-resource-key="product_name"]');
        name.value = '<b>literal product</b>';
        name.dispatchEvent(new Event('input', { bubbles: true }));
        check(third.querySelector('[data-row-heading]').textContent.includes('<b>literal product</b>')
            && !third.querySelector('[data-row-heading] b'), 'new product names remain text, not HTML');

        const material = add('materials');
        assertFields(material);
        change(field(material, 'project_material_id'), 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        field(material, 'condition').value = 'Needs repair';
        const other = add('materials');
        change(field(other, 'project_material_id'), 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        field(other, 'condition').value = 'Keep this Material draft';
        await remove(material, false);
        check(field(material, 'condition').value === 'Needs repair', 'cancelled Material removal keeps entered condition');
        await remove(material, true);
        check(other.dataset.prefix === 'materials[1]' && field(other, 'condition').value === 'Keep this Material draft'
            && !form.querySelector('[data-materials-removal]'), 'unsaved Material removal keeps other rows and creates no saved-deletion marker');
        check(add('materials').dataset.prefix === 'materials[2]', 'Material counter does not reuse removed indices');
    } else if (state.startsWith('restore-')) {
        const production = rows('production')[0];
        const material = rows('materials')[0];
        assertFields(production);
        assertFields(material);
        check(production.dataset.prefix === 'production[3]' && material.dataset.prefix === 'materials[7]', 'restored sparse row indices are preserved');
        check(field(production, 'remarks').value === 'Restored Production draft' && field(material, 'condition').value === 'Restored Material draft', 'restored row values survive initialization');
        const income = production.incomeGroup.querySelector('[data-kind="income"]');
        check(income.dataset.prefix === 'production[3][income][5]' && field(income, 'gross_income').value === '123.45', 'restored nested Income names and values are preserved');
        const before = snapshot();
        tab('materials');
        await remove(material, false);
        check(JSON.stringify(snapshot()) === JSON.stringify(before), 'restored draft cancellation preserves its payload');
        check(add('materials').dataset.prefix === 'materials[8]', 'new rows advance past restored indices');
    } else if (state === 'edit') {
        const material = rows('materials')[0];
        assertFields(material);
        check(!field(material, 'id').disabled && field(material, 'condition').value === 'Saved condition', 'saved row identity and values remain submitted');
        const before = snapshot();
        await remove(material, false);
        check(JSON.stringify(snapshot()) === JSON.stringify(before) && !form.querySelector('[data-materials-removal]'), 'cancelled saved removal does not stage deletion');
        const id = field(material, 'id').value;
        await remove(material, true);
        const markers = form.querySelectorAll('[data-materials-removal]');
        check(markers.length === 1 && markers[0].name === 'deleted_material_ids[]' && markers[0].value === id, 'confirmed saved Material removal stages its exact ID once');
    } else if (state === 'edit-production') {
        const production = rows('production')[0];
        check(!field(production, 'id').disabled && production.querySelector('[data-monitoring-remove-row]').disabled, 'saved Production identity remains submitted and removal remains disabled');
        check(field(production, 'project_product_id').disabled && production.querySelector('input[type="hidden"][data-key="project_product_id"]').value
            === '66666666-6666-4666-8666-666666666666', 'saved Production keeps the protected product and hidden identity proxy');
    }
    const ids = Array.from(form.querySelectorAll('[id]'), node => node.id);
    check(ids.length === new Set(ids).size, 'dynamic rows have no duplicate DOM IDs');
    return snapshot();
}
