import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/core/feedback.js', import.meta.url), 'utf8').replace(/^export /gm, '');

function fixture(payload = {}, { reduced = true, native = true, malformed = false } = {}) {
    const timers = [], events = new Map(), created = [], forms = [], restores = [];
    let observer;
    const document = { activeElement: null };
    function node() {
        const attributes = new Map(), listeners = new Map(), classes = new Set();
        return { dataset: {}, textContent: '', hidden: false, isConnected: true, disabled: false,
            get className() { return [...classes].join(' '); },
            set className(value) { classes.clear(); value.split(/\s+/).filter(Boolean).forEach(item => classes.add(item)); },
            classList: { add: value => classes.add(value), remove: value => classes.delete(value), contains: value => classes.has(value) },
            addEventListener(name, fn) { const list = listeners.get(name) ?? []; list.push(fn); listeners.set(name, list); },
            fire(name, event = {}) { event.target ??= this; for (const fn of listeners.get(name) ?? []) fn(event); },
            count: name => (listeners.get(name) ?? []).length,
            focus() { document.activeElement = this; }, closest() { return null; },
            setAttribute: (name, value) => attributes.set(name, value), getAttribute: name => attributes.get(name) ?? null,
            hasAttribute: name => attributes.has(name), append: child => created.push(child) };
    }
    const body = node(), heading = node(), opener = node();
    const selectors = ['acknowledge', 'title', 'message', 'success', 'error'];
    const controls = Object.fromEntries(selectors.map(name => [`[data-feedback-${name}]`, node()]));
    const modal = { ...node(), open: false, shows: 0,
        querySelector: selector => controls[selector],
        showModal() { this.open = true; this.shows++; },
        close() { this.open = false; for (const fn of events.get('close') ?? []) fn(); },
        animate() { this.animations = (this.animations ?? 0) + 1; return { finished: Promise.resolve() }; } };
    if (!native) delete modal.showModal;
    const data = { textContent: malformed ? 'invalid json' : JSON.stringify(payload) };
    const root = { querySelector: selector => selector === '[data-global-feedback]' ? modal : data };
    const other = { ...node(), open: false };
    Object.assign(document, { body, documentElement: node(), activeElement: opener,
        querySelector: selector => selector === '[data-feedback-root]' ? root
            : selector.startsWith('dialog[open]') ? (other.open ? other : null) : heading,
        querySelectorAll: selector => selector.startsWith('dialog[data-feedback-restore') ? restores.filter(item => item.dataset.feedbackRestore === 'true')
            : selector === 'form[data-feedback-error-bag]' ? forms
            : selector.includes('[aria-invalid') ? [] : created,
        getElementById: id => created.find(item => item.id === id), createElement: () => node(),
        addEventListener: (name, fn) => events.set(name, [...events.get(name) ?? [], fn]) });
    const context = { document, window: { matchMedia: () => ({ matches: reduced }) },
        MutationObserver: class { constructor(fn) { observer = fn; } observe() {} }, setTimeout: fn => timers.push(fn) };
    runInNewContext(source, context);
    const controller = context.initFeedback(root);
    return { controller, context, modal, controls, document, data, root, other, forms, restores, created, node, heading, opener,
        boot() { while (timers.length) timers.shift()(); }, mutate: () => observer(),
        closeOther() { other.open = false; for (const fn of events.get('close') ?? []) fn(); },
        ack() { controls['[data-feedback-acknowledge]'].fire('click'); } };
}

test('one initial notification, one controller, persistent until acknowledgement, and focus restoration', async () => {
    const f = fixture({ notification: { id: 'one', type: 'success', title: 'Saved', message: '<script>text</script>' } });
    assert.equal(f.context.initFeedback(f.root), f.controller);
    f.boot();
    assert.equal(f.modal.shows, 1);
    assert.equal(f.controls['[data-feedback-acknowledge]'].count('click'), 1);
    assert.equal(f.document.activeElement, f.controls['[data-feedback-acknowledge]']);
    assert.equal(f.controls['[data-feedback-message]'].textContent, '<script>text</script>');
    assert.equal(f.document.body.classList.contains('feedback-open'), true);
    for (const name of ['cancel', 'click', 'keydown']) {
        const event = { key: 'Tab', prevented: false, stopped: false,
            preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; } };
        f.modal.fire(name, event); assert.equal(event.prevented, true); assert.equal(f.modal.open, true);
    }
    f.boot(); assert.equal(f.modal.open, true);
    const escape = { key: 'Escape', stopPropagation() { this.stopped = true; } };
    f.modal.fire('keydown', escape); assert.equal(escape.stopped, true); assert.equal(f.modal.open, true);
    f.ack(); assert.equal(f.modal.open, false); assert.equal(f.document.activeElement, f.opener);
    assert.equal(f.document.body.classList.contains('feedback-open'), false);
});

test('queue suppresses repeated operation IDs but permits independent operations with identical text', async () => {
    const f = fixture(); f.boot();
    let acknowledged = 0;
    const result = { id: 'a', message: 'Saved', type: 'success' };
    const first = f.controller.show(result).then(() => acknowledged++);
    await f.controller.show(result);
    const second = f.controller.show({ ...result, id: 'b' }).then(() => acknowledged++);
    assert.equal(f.modal.shows, 1); assert.equal(acknowledged, 0);
    f.ack(); await first; assert.equal(f.modal.shows, 2); assert.equal(acknowledged, 1);
    f.ack(); await second; assert.equal(f.modal.open, false); assert.equal(acknowledged, 2);
});

test('specialized dialog owns focus first; feedback precedes restoration of the marked form only', () => {
    const f = fixture({ notification: { message: 'Review entries', type: 'error' } });
    const input = f.node(), formDialog = { ...f.node(), querySelector: () => input, showModal() { this.open = true; } };
    formDialog.dataset.feedbackRestore = 'true'; f.restores.push(formDialog);
    f.other.open = true; f.boot(); assert.equal(f.modal.open, false); assert.equal(formDialog.open, undefined);
    f.closeOther(); assert.equal(f.modal.open, true); assert.equal(formDialog.open, undefined);
    f.ack(); assert.equal(formDialog.open, true); assert.equal(f.document.activeElement, input);
    assert.equal(formDialog.dataset.feedbackRestore, undefined);
});

test('late form markers restore once even without a notification', () => {
    const f = fixture(); f.boot();
    const formDialog = { ...f.node(), querySelector: () => null, shows: 0, showModal() { this.shows++; } };
    formDialog.dataset.feedbackRestore = 'true'; f.restores.push(formDialog); f.mutate(); f.mutate();
    assert.equal(formDialog.shows, 1); assert.equal(f.modal.shows, 0);
});

test('field errors stay in their declared bag, support nested names, and reveal existing details', () => {
    const f = fixture({ errors: { monitoringVisit: { 'production.0.amount': ['<b>Correct this</b>'] } } });
    const detail = f.node(); detail.id = 'existing'; detail.hidden = true; detail.classList.add('field-error'); f.created.push(detail);
    const input = f.node(); input.name = 'production[0][amount]'; input.type = 'text'; input.value = 'Draft';
    input.setAttribute('aria-describedby', 'existing'); input.parentElement = f.node();
    const visit = { dataset: { feedbackErrorBag: 'monitoringVisit' }, elements: [input] };
    const areaInput = { ...f.node(), name: input.name, type: 'text' };
    f.forms.push(visit, { dataset: { feedbackErrorBag: 'default' }, elements: [areaInput] }); f.boot();
    assert.equal(input.value, 'Draft'); assert.equal(input.getAttribute('aria-invalid'), 'true');
    assert.equal(detail.textContent, '<b>Correct this</b>'); assert.equal(detail.hidden, false);
    assert.equal(areaInput.getAttribute('aria-invalid'), null);
});

test('native-dialog absence and malformed payload keep the server fallback visible', () => {
    const unsupported = fixture({}, { native: false }); assert.equal(unsupported.controller, null);
    assert.equal(unsupported.document.documentElement.classList.contains('feedback-ready'), false);
    const f = fixture({}, { malformed: true });
    assert.equal(f.document.documentElement.classList.contains('feedback-ready'), false);
    assert.equal(f.modal.open, false);
});

test('optional acknowledgement animation and reduced-motion branch both close only after a button click', async () => {
    const f = fixture({}, { reduced: false }); f.boot();
    const done = f.controller.show({ message: 'Saved' }); assert.equal(f.modal.animations, undefined);
    f.ack(); await done; assert.equal(f.modal.animations, 1); assert.equal(f.modal.open, false);
    const reduced = fixture(); reduced.boot(); reduced.controller.show({ message: 'Saved' }); reduced.ack();
    assert.equal(reduced.modal.animations, undefined);
});

test('safe AJAX classifications preserve meaningful status differences', () => {
    const f = fixture();
    const expected = { 403: 'Access Denied', 422: 'Review Your Entries', 409: 'Information Changed', 429: 'Please Wait', 500: 'Report Unavailable', 503: 'Report Unavailable', 419: 'Session Expired' };
    for (const [status, title] of Object.entries(expected)) assert.equal(f.context.errorFeedback(Number(status), { context: 'Report' }).title, title);
    assert.equal(f.context.errorFeedback(0, { network: true }).title, 'Result Not Confirmed');
});

test('array field details are reused and linked once across repeated validation responses', () => {
    const f = fixture(), input = f.node(), container = f.node();
    input.name = 'classification_ids[]'; input.type = 'checkbox'; input.value = 'synthetic-selection'; input.parentElement = container;
    const form = { elements: [input] };
    const errors = { 'classification_ids.0': ['Choose an available classification.'] };
    f.context.applyFormErrors(form, errors); f.context.applyFormErrors(form, errors);
    assert.equal(f.created.length, 1); assert.equal(input.getAttribute('aria-invalid'), 'true');
    assert.equal(input.getAttribute('aria-describedby'), f.created[0].id); assert.equal(input.value, 'synthetic-selection');
});
