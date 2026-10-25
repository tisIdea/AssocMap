import assert from 'node:assert/strict';
import test from 'node:test';
import { createMonitoringRow } from '../../resources/js/pages/monitoring-row-template.js';

function template(fields = ['id', 'remarks']) {
    const clones = [];
    const source = { content: { firstElementChild: { cloneNode(deep) {
        assert.equal(deep, true);
        const labels = new Map();
        const row = { querySelectorAll: () => row.inputs,
            querySelector(selector) { return labels.get(selector) ?? null; } };
        row.inputs = fields.map(key => {
            const input = { dataset: { key }, name: 'template-name', id: 'template-id', value: 'template-value',
                disabled: true, closest: () => row };
            labels.set(`[data-label="${key}"]`, { setAttribute(name, value) { this[name] = value; } });
            return input;
        });
        row.labels = labels;
        clones.push(row);
        return row;
    } } } };
    return { source, clones };
}

test('cloned row fields receive scoped names, stable IDs, matching labels and restored values', () => {
    const fixture = template();
    const row = createMonitoringRow({ template: fixture.source, prefix: 'production[3]', values: { id: 'saved-id', remarks: 'Draft text' } });
    assert.equal(row, fixture.clones[0]);
    assert.deepEqual(row.inputs.map(input => [input.name, input.id, input.value]), [
        ['production[3][id]', 'monitoring-production-3--id-', 'saved-id'],
        ['production[3][remarks]', 'monitoring-production-3--remarks-', 'Draft text'],
    ]);
    for (const input of row.inputs) assert.equal(row.labels.get(`[data-label="${input.dataset.key}"]`).for, input.id);
    assert.equal(row.inputs[0].disabled, false);
});

test('empty rows clear template values and keep unsaved identity out of FormData', () => {
    const row = createMonitoringRow({ template: template().source, prefix: 'materials[0]' });
    assert.deepEqual(row.inputs.map(input => input.value), ['', '']);
    assert.equal(row.inputs[0].disabled, true);
    assert.equal(row.inputs[1].disabled, true, 'Non-identity disabled state belongs to the template/editor.');
});

test('only nullish values become empty; explicit zero and false survive binding', () => {
    const row = createMonitoringRow({ template: template(['activity_count', 'total_quantity', 'flag', 'remarks']).source,
        prefix: 'production[4][monthly_details][2]', values: { activity_count: 0, total_quantity: '0.00', flag: false, remarks: null } });
    assert.deepEqual(row.inputs.map(input => input.value), [0, '0.00', false, '']);
    assert.equal(row.inputs[0].name, 'production[4][monthly_details][2][activity_count]');
});

test('nested row fields are not rebound by their parent row', () => {
    const fixture = template(['remarks']);
    const original = fixture.source.content.firstElementChild.cloneNode;
    fixture.source.content.firstElementChild.cloneNode = deep => {
        const row = original(deep);
        row.inputs.push({ dataset: { key: 'month' }, name: 'nested-name', id: 'nested-id', value: '5', closest: () => ({}) });
        return row;
    };
    const row = createMonitoringRow({ template: fixture.source, prefix: 'production[2]', values: { remarks: 'Parent', month: '6' } });
    assert.equal(row.inputs[0].value, 'Parent');
    assert.deepEqual([row.inputs[1].name, row.inputs[1].id, row.inputs[1].value], ['nested-name', 'nested-id', '5']);
});

test('repeated creation returns independent rows without changing the template or draft object', () => {
    const fixture = template();
    const values = Object.freeze({ id: 'saved', remarks: '<script>literal draft</script>' });
    const first = createMonitoringRow({ template: fixture.source, prefix: 'production[1][income][5]', values });
    const second = createMonitoringRow({ template: fixture.source, prefix: 'production[1][income][6]', values });
    assert.notEqual(first, second);
    assert.equal(first.inputs[1].value, '<script>literal draft</script>');
    first.inputs[1].value = 'Changed row';
    assert.equal(second.inputs[1].value, values.remarks);
    assert.equal(first.inputs[1].name, 'production[1][income][5][remarks]');
    assert.equal(second.inputs[1].name, 'production[1][income][6][remarks]');
    assert.deepEqual(values, { id: 'saved', remarks: '<script>literal draft</script>' });
});

test('an absent optional label does not prevent field binding', () => {
    const fixture = template(['month']);
    const original = fixture.source.content.firstElementChild.cloneNode;
    fixture.source.content.firstElementChild.cloneNode = deep => {
        const row = original(deep);
        row.labels.clear();
        return row;
    };
    const row = createMonitoringRow({ template: fixture.source, prefix: 'production[0][monthly_details][0]', values: { month: 4 } });
    assert.equal(row.inputs[0].value, 4);
});
