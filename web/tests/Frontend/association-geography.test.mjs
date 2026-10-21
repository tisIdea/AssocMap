import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/pages/admin/forms.js', import.meta.url), 'utf8');
const sync = source.slice(source.indexOf('function syncForm(form)'), source.indexOf('function initAreaList()'));

function fixture(direct = true) {
    class Option { constructor(text, value) { this.text = text; this.value = value; } }
    const select = value => ({ value, options: [], replaceChildren(...options) { this.options = options; this.value = ''; }, add(option) { this.options.push(option); } });
    const fields = {
        '[data-association-municipality]': select('m1'),
        '[data-association-barangay]': select('b1'),
        '[name=area_id]': select('a1'),
        '[data-association-area-cleared]': { hidden: true },
        '[name=specific_address]': { value: 'Independent ñ / #2 address' },
    };
    const form = {
        dataset: {
            directGeography: String(direct),
            areaRecords: JSON.stringify([{ value: 'a1', label: 'Recorded area', municipality_id: 'm1', barangay_id: 'b1', barangay: 'Barangay One' }]),
            barangayRecords: JSON.stringify([{ value: 'b1', label: 'Barangay One', municipality_id: 'm1' }, { value: 'b2', label: 'No Area', municipality_id: 'm1' }, { value: 'b3', label: 'New City', municipality_id: 'm2' }]),
        },
        matches: selector => selector === '[data-association-form]',
    };
    const context = { Option, $: selector => fields[selector] ?? null };
    runInNewContext(sync, context);
    return { fields, run: () => context.syncForm(form) };
}

test('prepared cascade preserves existing selections and independent address', () => {
    const { fields, run } = fixture();
    run();
    assert.equal(fields['[name=area_id]'].value, 'a1');
    assert.equal(fields['[name=area_id]'].options[0].text, 'No Specific Area');
    assert.deepEqual(fields['[data-association-barangay]'].options.map(option => option.value), ['', 'b1', 'b2']);
    assert.equal(fields['[name=specific_address]'].value, 'Independent ñ / #2 address');
});

test('barangay and municipality changes visibly clear incompatible Area without clearing address', () => {
    const { fields, run } = fixture();
    run();
    fields['[data-association-barangay]'].value = 'b2';
    run();
    assert.equal(fields['[name=area_id]'].value, '');
    assert.equal(fields['[data-association-area-cleared]'].hidden, false);
    assert.equal(fields['[name=area_id]'].options[0].text, 'No Specific Area');
    fields['[data-association-municipality]'].value = 'm2';
    run();
    fields['[data-association-barangay]'].value = 'b3';
    run();
    assert.equal(fields['[data-association-barangay]'].value, 'b3');
    assert.equal(fields['[name=specific_address]'].value, 'Independent ñ / #2 address');
});

test('legacy cascade still derives its choices from recorded Areas', () => {
    const { fields, run } = fixture(false);
    run();
    assert.deepEqual(fields['[data-association-barangay]'].options.map(option => option.value), ['', 'b1']);
    assert.equal(fields['[name=area_id]'].options[0].text, 'Select specific area');
});

for (const area of ['', 'a1']) {
    for (const address of ['', 'Independent address']) {
        test(`State 4 cascade preserves Area=${area || 'none'} and address=${address || 'blank'} independently`, () => {
            const { fields, run } = fixture();
            fields['[name=area_id]'].value = area;
            fields['[name=specific_address]'].value = address;
            run();
            assert.equal(fields['[name=area_id]'].value, area);
            assert.equal(fields['[name=specific_address]'].value, address);
        });
    }
}

test('Specific Area cascade preserves a matching Barangay and clears it on Municipality change', () => {
    class Option { constructor(text, value) { this.text = text; this.value = value; } }
    const municipality = { value: 'm1' };
    const barangay = { value: 'b1', options: [], replaceChildren(...options) { this.options = options; }, add(option) { this.options.push(option); } };
    const controls = {
        'select[name=area_type]': { value: 'Specific Area' },
        '[data-area-edit-type]': {}, '[data-area-method]': {}, '[name=area_record_id]': {},
        '[data-specific-municipality]': municipality, '[name=barangay_id]': barangay, '[data-area-submit]': {},
    };
    const form = { dataset: { mode: 'create', specificAreaAction: '/admin/areas',
        barangayRecords: JSON.stringify([{ value: 'b1', label: 'One', municipality_id: 'm1' }, { value: 'b2', label: 'Two', municipality_id: 'm2' }]) },
        matches: selector => selector === '[data-area-form]' };
    const context = { Option, $: selector => controls[selector] ?? null, $$: () => [], setFieldVisibility() {} };
    runInNewContext(sync, context);
    context.syncForm(form);
    assert.equal(barangay.value, 'b1');
    assert.equal(form.action, '/admin/areas');
    municipality.value = 'm2';
    context.syncForm(form);
    assert.equal(barangay.value, '');
    assert.deepEqual(barangay.options.map(option => option.value), ['', 'b2']);
    assert.equal(controls['select[name=area_type]'].disabled, false);
});
