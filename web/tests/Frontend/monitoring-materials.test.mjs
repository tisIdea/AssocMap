import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/monitoring/partials/editor-script.blade.php', import.meta.url), 'utf8').replace(/\r\n/g, '\n');
const save = source.match(/^    async function saveMaterials\(\) \{[\s\S]*?^    \}/m)?.[0];
const removal = source.match(/^    function markMaterialsRemoval\(id\) \{[\s\S]*?^    \}/m)?.[0];
const destination = source.match(/^    function safeMaterialsDestination\(value\) \{[\s\S]*?^    \}/m)?.[0];
assert.ok(save && removal && destination, 'Test the real Materials save and removal helpers.');

function editor(response, reject = false) {
    const fields = { materials_revision: 'a'.repeat(64), condition: 'Needs repair', remarks: 'unsaved draft', _token: 'synthetic-csrf', _method: 'PATCH' };
    const message = { textContent: '' };
    const errors = { items: [], replaceChildren() { this.items = []; }, append(item) { this.items.push(item); } };
    Object.defineProperty(errors, 'children', { get() { return this.items; } });
    const feedback = { hidden: true, focused: false, focus() { this.focused = true; },
        querySelector(selector) { return selector === '[data-materials-message]' ? message : errors; } };
    const submit = { disabled: true };
    const status = { textContent: 'Saving Monitoring. Please wait.' };
    const form = { action: '/admin/monitoring/synthetic/sections/materials', fields, busy: true,
        removeAttribute(name) { if (name === 'aria-busy') this.busy = false; },
        querySelector(selector) { return selector === '[data-materials-feedback]' ? feedback : selector === '[type="submit"]' ? submit : status; } };
    const calls = []; const redirects = [];
    const conflicts = [];
    const context = { URL, form, submitting: true, FormData: class { constructor(value) { this.values = { ...value.fields }; } },
        materialsConflict: { open: result => conflicts.push(result) },
        document: { createElement: tag => ({ tag, textContent: '' }) },
        window: { location: { origin: 'https://assocmap.test', href: 'https://assocmap.test/admin/monitoring/synthetic/sections/materials/edit', assign: path => redirects.push(path) } },
        fetch: async (url, options) => {
            calls.push({ url, options });
            if (reject) throw new Error('Synthetic network failure');
            return { ok: response.status >= 200 && response.status < 300, status: response.status, json: async () => response.body };
        } };
    runInNewContext(destination + '\n' + save, context);
    return { fields, feedback, message, errors, submit, status, form, calls, redirects, conflicts, context, save: () => context.saveMaterials() };
}

test('HTTP 409 keeps values, removals and old revision, displays conflict and never navigates', async () => {
    const fixture = editor({ status: 409, body: { code: 'monitoring_materials_conflict', errors: { materials_revision: ['Synthetic conflict'] } } });
    fixture.fields.deleted_materials_ids = ['synthetic-saved-id'];
    const before = JSON.stringify(fixture.fields);
    await fixture.save();
    assert.equal(JSON.stringify(fixture.fields), before);
    assert.equal(fixture.conflicts.length, 1);
    assert.equal(fixture.feedback.hidden, true);
    assert.equal(fixture.redirects.length, 0);
    assert.equal(fixture.submit.disabled, false);
    assert.equal(fixture.context.submitting, false);
    assert.equal(fixture.form.busy, false);
    assert.equal(fixture.calls[0].options.method, 'POST');
    assert.equal(fixture.calls[0].options.headers.Accept, 'application/json');
    assert.equal(fixture.calls[0].options.body.values._token, 'synthetic-csrf');
});

test('HTTP 422 renders validation text without replacing the draft', async () => {
    const fixture = editor({ status: 422, body: { errors: { materials: ['<script>synthetic validation</script>'] } } });
    await fixture.save();
    assert.equal(fixture.fields.condition, 'Needs repair');
    assert.equal(fixture.errors.items[0].tag, 'li');
    assert.equal(fixture.errors.items[0].textContent, '<script>synthetic validation</script>');
    assert.equal(fixture.redirects.length, 0);
});

test('uncertain network result retains draft and requests saved-state review', async () => {
    const fixture = editor(null, true);
    await fixture.save();
    assert.match(fixture.message.textContent, /could not be confirmed/);
    assert.equal(fixture.fields.remarks, 'unsaved draft');
    assert.equal(fixture.redirects.length, 0);
    assert.equal(fixture.submit.disabled, false);
});

test('successful save follows the server workspace destination', async () => {
    const fixture = editor({ status: 200, body: { redirect: '/admin/monitoring/synthetic/workspace/materials' } });
    await fixture.save();
    assert.deepEqual(fixture.redirects, ['https://assocmap.test/admin/monitoring/synthetic/workspace/materials']);
    assert.equal(fixture.feedback.hidden, true);
});

test('confirmed Materials removal stages one saved observation ID at form level', () => {
    const markers = [];
    const form = { querySelectorAll: () => markers, append: input => markers.push(input) };
    const context = { form, document: { createElement: () => ({ dataset: {} }) } };
    runInNewContext(removal, context);
    context.markMaterialsRemoval('synthetic-id'); context.markMaterialsRemoval('synthetic-id');
    assert.equal(markers.length, 1); assert.equal(markers[0].name, 'deleted_material_ids[]');
    assert.equal(markers[0].value, 'synthetic-id');
});

test('a different HTTP 409 stays inline with its own validation and never opens the Materials conflict modal', async () => {
    const fixture = editor({ status: 409, body: { errors: { project_id: ['Synthetic association changed'] } } });
    await fixture.save();
    assert.equal(fixture.conflicts.length, 0);
    assert.equal(fixture.feedback.hidden, false);
    assert.equal(fixture.errors.items[0].textContent, 'Synthetic association changed');
});

test('an unsafe success destination never redirects or reports a successful save', async () => {
    const fixture = editor({ status: 200, body: { redirect: 'https://foreign.test/path' } });
    await fixture.save();
    assert.equal(fixture.redirects.length, 0);
    assert.equal(fixture.feedback.hidden, false);
    assert.match(fixture.message.textContent, /could not be confirmed/);
});

test('typed Materials 409 has one specialized owner even with the global controller present', async () => {
    const fixture = editor({ status: 409, body: { code: 'monitoring_materials_conflict', errors: { materials_revision: ['Changed.'] } } });
    const generic = [];
    fixture.context.window.AssocMapFeedback = { fields() { generic.push('fields'); }, error() { generic.push('error'); } };
    await fixture.save();
    assert.equal(fixture.conflicts.length, 1); assert.equal(generic.length, 0);
    assert.equal(fixture.fields.condition, 'Needs repair'); assert.equal(fixture.fields.remarks, 'unsaved draft');
});

test('other Materials failures use one global result, retain field details and never invoke the conflict dialog', async () => {
    for (const status of [403, 409, 422, 429, 500, 503, 0]) {
        const fixture = editor({ status, body: { errors: { condition: ['Correct value.'] } } }, !status);
        const generic = [], fields = [];
        fixture.context.window.AssocMapFeedback = { fields: (form, errors) => fields.push(errors), error: (value, options) => generic.push({ value, options }) };
        await fixture.save();
        assert.equal(generic.length, 1); assert.equal(generic[0].value, status); assert.equal(generic[0].options.network, !status);
        assert.equal(fixture.conflicts.length, 0); assert.equal(fixture.message.hidden, true);
        if (status === 422) assert.equal(fields[0].condition[0], 'Correct value.');
        assert.equal(fixture.fields.condition, 'Needs repair'); assert.equal(fixture.redirects.length, 0);
    }
});

test('saved Materials deletion marker is staged only inside confirmed removal', () => {
    const start = source.indexOf('            confirmRemoval(`Remove ${label}?`');
    const end = source.indexOf('    form.addEventListener(\'change\'', start);
    const callback = source.slice(start, end);
    assert.match(callback, /kind === 'materials' && saved\) markMaterialsRemoval/);
    assert.match(source, /Object.values\(values.deleted_material_ids \?\? \{\}\).forEach\(markMaterialsRemoval\)/);
});

test('removal cancellation keeps the draft and confirmation runs the staged action once', () => {
    const start = source.indexOf('    const removalDialog =');
    const end = source.indexOf('    const projectSelect =', start);
    const handlers = {}, controls = {};
    for (const key of ['data-removal-confirm', 'data-removal-cancel']) controls[key] = {
        addEventListener(name, callback) { handlers[key] = callback; }, focus() { this.focused = true; },
    };
    const dialog = { open: false, setAttribute() {},
        querySelector: selector => controls[selector.slice(1, -1)],
        addEventListener(name, callback) { handlers[name] = callback; },
        showModal() { this.open = true; }, close(result) { this.open = false; this.returnValue = result; handlers.close(); },
    };
    const labels = {};
    const context = { document: { getElementById(id) { return id === 'monitoring-removal-modal' ? dialog : (labels[id] ??= {}); } } };
    runInNewContext(source.slice(start, end), context);
    const opener = { isConnected: true, focus() { this.focused = true; } };
    const draft = { condition: 'Needs repair', removed: [] };
    context.confirmRemoval('Remove?', 'Synthetic confirmation', opener, () => draft.removed.push('saved-id'));
    handlers['data-removal-cancel']();
    assert.deepEqual(draft, { condition: 'Needs repair', removed: [] }); assert.equal(opener.focused, true);
    context.confirmRemoval('Remove?', 'Synthetic confirmation', opener, () => draft.removed.push('saved-id'));
    handlers['data-removal-confirm'](); handlers['data-removal-confirm']();
    assert.deepEqual(draft.removed, ['saved-id']); assert.equal(draft.condition, 'Needs repair');
});
