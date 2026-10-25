import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/monitoring/partials/editor-script.blade.php', import.meta.url), 'utf8').replace(/\r\n/g, '\n');
const dialog = source.match(/^    function initIncomeConflictDialog\(\) \{[\s\S]*?^    \}/m)?.[0];
const destination = source.match(/^    function safeIncomeDestination\(value\) \{[\s\S]*?^    \}/m)?.[0];
assert.ok(dialog && destination);

function element() {
    const listeners = new Map();
    return { hidden: false, isConnected: true, textContent: '',
        addEventListener(name, callback) { const callbacks = listeners.get(name) ?? []; callbacks.push(callback); listeners.set(name, callbacks); },
        fire(name) { for (const callback of listeners.get(name) ?? []) callback(); },
        count(name) { return (listeners.get(name) ?? []).length; },
        focus() { this.focused = true; }, setAttribute() {}, removeAttribute(name) { delete this[name]; } };
}
function fixture() {
    const selectors = ['data-income-conflict-actions', 'data-income-refresh-confirmation', 'data-income-conflict-cancel',
        'data-income-refresh', 'data-income-refresh-cancel', 'data-income-refresh-confirm', 'data-income-latest', 'data-income-conflict-message'];
    const controls = Object.fromEntries(selectors.map(key => [key, element()]));
    const modal = { ...element(), open: false, shows: 0,
        querySelector: selector => controls[selector.slice(1, -1)],
        showModal() { this.open = true; this.shows++; }, close() { this.open = false; this.fire('close'); } };
    const submit = element(), reloads = [], redirects = [];
    const context = { URL, form: { querySelector: () => submit }, document: { getElementById: () => modal },
        window: { location: { origin: 'https://assocmap.test', href: 'https://assocmap.test/admin/monitoring/example/sections/income/edit',
            reload: () => reloads.push(true), assign: url => redirects.push(url) } } };
    runInNewContext(destination + '\n' + dialog, context);
    const controller = context.initIncomeConflictDialog();
    return { controls, modal, submit, reloads, redirects, context, controller,
        click: name => controls['data-income-' + name].fire('click'),
        open: (changes = {}) => controller.open({ message: 'Synthetic newer changes', latest_saved_url: '/admin/monitoring/example/sections/income/edit', ...changes }) };
}

test('conflict dialog is labelled, focuses Cancel and links to the same-origin server destination', () => {
    const f = fixture(); f.open();
    assert.equal(f.modal.open, true);
    assert.equal(f.controls['data-income-conflict-cancel'].focused, true);
    assert.equal(f.controls['data-income-latest'].href, 'https://assocmap.test/admin/monitoring/example/sections/income/edit');
    assert.equal(f.redirects.length + f.reloads.length, 0);
    const markup = readFileSync(new URL('../../resources/views/monitoring/partials/income-conflict-modal.blade.php', import.meta.url), 'utf8');
    assert.match(markup, /data-income-latest target="_blank" rel="noopener noreferrer"/);
    assert.match(markup, /title="Income Record Updated"/);
});

test('refresh requires a second explicit confirmation and cancel restores the safe action state', () => {
    const f = fixture(); f.open();
    f.click('refresh-confirm'); assert.equal(f.reloads.length, 0);
    f.click('refresh'); assert.equal(f.reloads.length, 0);
    assert.equal(f.controls['data-income-refresh-confirmation'].hidden, false);
    assert.equal(f.controls['data-income-refresh-cancel'].focused, true);
    f.click('refresh-cancel'); f.click('refresh-confirm'); assert.equal(f.reloads.length, 0);
    assert.equal(f.controls['data-income-conflict-actions'].hidden, false);
    f.click('refresh'); f.click('refresh-confirm'); f.click('refresh-confirm');
    assert.equal(f.reloads.length, 1);
});

test('Cancel and native close restore focus without navigating or refreshing', () => {
    const f = fixture(); f.open(); f.click('conflict-cancel');
    assert.equal(f.modal.open, false); assert.equal(f.submit.focused, true);
    f.open(); f.click('refresh'); f.modal.close(); f.open();
    assert.equal(f.controls['data-income-refresh-confirmation'].hidden, true);
    assert.equal(f.reloads.length + f.redirects.length, 0);
});

test('untrusted destinations are blocked and server messages are assigned as escaped text', () => {
    const f = fixture();
    for (const url of ['javascript:alert(1)', 'https://foreign.test/path', '//foreign.test/path', 'https://user:password@assocmap.test/path', '']) {
        f.open({ latest_saved_url: url, message: '<script>synthetic</script>' });
        assert.equal(f.controls['data-income-latest'].hidden, true);
        assert.equal(f.controls['data-income-latest'].href, undefined);
        assert.equal(f.controls['data-income-conflict-message'].textContent, '<script>synthetic</script>');
        assert.equal(f.context.safeIncomeDestination(url), null);
    }
});

test('repeated dialog initialization attaches each handler once', () => {
    const f = fixture();
    assert.equal(f.context.initIncomeConflictDialog(), f.controller);
    assert.equal(f.controls['data-income-refresh'].count('click'), 1);
    assert.equal(f.controls['data-income-refresh-confirm'].count('click'), 1);
    assert.equal(f.modal.count('close'), 1);
});

test('reinitializing a marked Monitoring editor attaches no more handlers', () => {
    const form = { dataset: { monitoringInitialized: 'true' }, addEventListener() { assert.fail('Duplicate handler'); } };
    const executable = source.replace(/^import .*;\n/gm, '').replace(/<\/?script[^>]*>/g, '').replace(/\{\{[^\n]*?\}\}/g, 'null');
    runInNewContext(executable, { document: { getElementById: () => form } });
});

test('Production submission guard permits one pending request and restores controls on pageshow', () => {
    const listener = source.match(/^    form.addEventListener\('submit', event => \{[\s\S]*?^    \}\);/m)?.[0];
    const pageshow = source.match(/^    window.addEventListener\('pageshow', \(\) => \{[\s\S]*?^    \}\);/m)?.[0];
    assert.ok(listener && pageshow);
    const callbacks = {}, submit = {}, status = {};
    const form = { elements: [], querySelectorAll: () => [],
        addEventListener: (name, callback) => { callbacks[name] = callback; },
        querySelector: selector => selector === '[type="submit"]' ? submit : status,
        setAttribute() {}, removeAttribute() {} };
    const context = { form, submitting: false, editingRecord: null, activeSection: 'production',
        refreshQuarterAvailability: () => true, refreshUnique() {}, rootRows: () => [], refreshIncome() {}, refreshMonthly() {}, isolateSection() {},
        window: { addEventListener: (name, callback) => { callbacks[name] = callback; } } };
    runInNewContext(listener + '\n' + pageshow, context);
    const first = { prevented: false, preventDefault() { this.prevented = true; } }, second = { prevented: false, preventDefault() { this.prevented = true; } };
    callbacks.submit(first); callbacks.submit(second);
    assert.equal(first.prevented, false); assert.equal(second.prevented, true); assert.equal(submit.disabled, true);
    callbacks.pageshow(); assert.equal(submit.disabled, false); assert.equal(context.submitting, false); assert.equal(status.textContent, '');
});
