import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/core/dialogs.js', import.meta.url), 'utf8');
const start = source.indexOf("        if (button.matches('[data-area-add], [data-area-edit]'))");
const end = source.indexOf("        if (button.matches('[data-area-view]'))", start);
const binding = source.slice(start, end);
assert.ok(start >= 0 && end > start);

function openArea({ editing = false, record = null, dataset = {} } = {}) {
    const fields = new Map();
    const get = selector => {
        if (!fields.has(selector)) fields.set(selector, { value: '', disabled: false, textContent: '' });
        return fields.get(selector);
    };
    const parent = get('[name=municipality_id]');
    parent.options = [{ value: 'active-parent' }];
    parent.add = option => parent.options.push(option);
    const form = { dataset: {}, reset() { this.resetCount = (this.resetCount ?? 0) + 1; },
        dispatchEvent(event) { this.lastEvent = event.type; } };
    const details = ['province', 'region', 'source', 'status'].map(key => ({ dataset: { areaEditorDetail: key } }));
    const names = [{ value: '' }, { value: '' }];
    const button = { dataset,
        matches: () => true,
        hasAttribute: name => name === 'data-area-edit' && editing,
        closest: () => ({ dataset: { areaRecord: JSON.stringify(record) } }),
    };
    const context = { button, id: '', Event: class { constructor(type) { this.type = type; } },
        Option: class { constructor(label, value) { this.label = label; this.value = value; this.dataset = {}; } },
        $: selector => selector === '[data-area-form]' ? form : (selector === '[data-area-validation]' ? null : get(selector)),
        $$: selector => selector === '[name=name]' ? names : (selector === '[data-area-editor-detail]' ? details : []),
    };
    runInNewContext(binding, context);
    return { fields, get, parent, form, details, names, context };
}

test('Add Area keeps its existing municipality default and opens the shared editor', () => {
    const result = openArea();
    assert.equal(result.context.id, 'area-editor');
    assert.equal(result.form.dataset.mode, 'create');
    assert.equal(result.get('select[name=area_type]').value, 'Municipality');
    assert.equal(result.form.lastEvent, 'change');
});

test('Add Barangay uses the selected municipality without turning creation into an update', () => {
    const result = openArea({ dataset: { areaCreateType: 'Barangay', areaParent: 'active-parent' } });
    assert.equal(result.get('select[name=area_type]').value, 'Barangay');
    assert.equal(result.parent.value, 'active-parent');
    assert.equal(result.form.dataset.updateAction, '');
    assert.equal(result.get('[name=area_record_id]').value, '');
});

test('Add address supplies the real parent and barangay IDs for the existing form synchronizer', () => {
    const result = openArea({ dataset: { areaCreateType: 'Specific Area', areaParent: 'active-parent', areaBarangay: 'current-barangay' } });
    assert.equal(result.get('select[name=area_type]').value, 'Specific Area');
    assert.equal(result.get('[data-specific-municipality]').value, 'active-parent');
    assert.equal(result.get('[name=barangay_id]').value, 'current-barangay');
    assert.equal(result.get('[name=address]').value, '');
    assert.equal(result.form.lastEvent, 'change');
});

test('editing a historical barangay preserves its inactive parent and existing update destination', () => {
    const record = { id: 'barangay-id', area_type: 'Barangay', name: 'Historical barangay',
        municipality_id: 'inactive-parent', municipality: 'Inactive parent', update_url: '/existing-update',
        psgc_code: 'PSGC-1', province: 'Stored province', region: 'Stored region', status: 'Inactive', source: 'PSGC' };
    const result = openArea({ editing: true, record });
    assert.equal(result.form.dataset.mode, 'edit');
    assert.equal(result.form.dataset.updateAction, '/existing-update');
    assert.equal(result.parent.value, 'inactive-parent');
    assert.equal(result.parent.options.at(-1).value, 'inactive-parent');
    assert.equal(result.parent.options.at(-1).dataset.currentParent, '');
    assert.equal(result.get('[name=area_record_id]').value, 'barangay-id');
    assert.deepEqual(result.names.map(field => field.value), ['Historical barangay', 'Historical barangay']);
    assert.equal(result.details.find(field => field.dataset.areaEditorDetail === 'status').textContent, 'Inactive');
});
