import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/monitoring/partials/visit-script.blade.php', import.meta.url), 'utf8');
const init = source.match(/    function initVisitReport\(form\) \{[\s\S]*?\n    \}/)?.[0];
assert.ok(init);
function fixture(id = '', name = '', saved = null) {
    const field = value => ({ value, listeners: {},
        addEventListener(name, callback) { (this.listeners[name] ??= []).push(callback); },
        fire(name) { for (const callback of this.listeners[name] ?? []) callback(); },
        setCustomValidity(message) { this.message = message; }, setAttribute() {} });
    const officer = field(id), conductor = field(name), error = {}, submit = {}, status = {};
    const callbacks = {}, pages = [];
    const form = { dataset: { savedConductor: JSON.stringify(saved) }, elements: { conducted_by_user_id: officer, conducted_by: conductor },
        querySelector(selector) { return selector === '[data-visit-conductor-error]' ? error : selector === '[type="submit"]' ? submit : selector === '[data-visit-submit-status]' ? status : null; },
        addEventListener(name, callback) { (callbacks[name] ??= []).push(callback); },
        checkValidity() { return !conductor.message; }, reportValidity() { this.reported = true; }, setAttribute() {}, removeAttribute() {} };
    const context = { window: { addEventListener: (name, callback) => pages.push(callback) } };
    runInNewContext(init, context); context.initVisitReport(form);
    return { officer, conductor, error, submit, status, form, callbacks, pages, context,
        save() { const event = { prevented: false, preventDefault() { this.prevented = true; } }; callbacks.submit[0](event); return event; } };
}

test('one conductor option is valid; both and neither fail without clearing either field', () => {
    for (const [id, name, valid] of [['officer', '', true], ['', 'Synthetic Observer', true], ['officer', 'Synthetic Observer', false], ['', '', false]]) {
        const f = fixture(id, name);
        assert.equal(f.error.hidden, valid); assert.equal(f.save().prevented, !valid);
        assert.equal(f.officer.value, id); assert.equal(f.conductor.value, name);
        if (!valid) assert.match(f.error.textContent, /exactly one option/);
    }
});
test('clearing the alternate choice fixes validation and retains the remaining value', () => {
    const f = fixture('officer', 'Synthetic Observer');
    f.officer.value = ''; f.officer.fire('change');
    assert.equal(f.error.hidden, true); assert.equal(f.conductor.value, 'Synthetic Observer'); assert.equal(f.conductor.message, '');
});
test('unchanged historical attribution including a missing conductor remains eligible for server review', () => {
    for (const saved of [{ id: null, name: null }, { id: 'previous-officer', name: null }]) {
        const f = fixture(saved.id ?? '', saved.name ?? '', saved);
        assert.equal(f.save().prevented, false);
        f.conductor.value = 'Changed historical attribution'; f.conductor.fire('input');
        if (saved.id) assert.equal(f.error.hidden, false);
    }
});
test('initialization is idempotent and duplicate visit clicks permit one pending submission', () => {
    const f = fixture('officer'); f.context.initVisitReport(f.form);
    assert.equal(f.callbacks.submit.length, 1); assert.equal(f.officer.listeners.change.length, 1);
    assert.equal(f.save().prevented, false); assert.equal(f.save().prevented, true); assert.equal(f.submit.disabled, true);
    f.pages[0](); assert.equal(f.submit.disabled, false); assert.equal(f.status.textContent, '');
});
