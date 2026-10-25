import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const editor = readFileSync(new URL('../../resources/views/monitoring/partials/editor-script.blade.php', import.meta.url), 'utf8').replace(/\r\n/g, '\n');
const feedback = readFileSync(new URL('../../resources/js/core/feedback.js', import.meta.url), 'utf8').replace(/^export /gm, '');
const restoration = editor.match(/^    if \(restore\) \{[\s\S]*?^    \}/m)?.[0];
const clearing = editor.match(/        form\.querySelectorAll\('\[data-field-error\]'\)[\s\S]*?(?=        form.action =)/)?.[0];
assert.ok(restoration && clearing);

function fixture(name = 'overall_remarks') {
    const document = { activeElement: null };
    const details = [];
    function node() {
        const attributes = new Map();
        return { dataset: {}, className: '', hidden: false, textContent: '', id: '',
            classList: { contains(value) { return this.owner.className.split(/\s+/).includes(value); } },
            setAttribute(key, value) { attributes.set(key, value); },
            getAttribute(key) { return attributes.get(key) ?? null; },
            hasAttribute(key) { return key === 'data-field-error' ? Object.hasOwn(this.dataset, 'fieldError') : attributes.has(key); },
            removeAttribute(key) { attributes.delete(key); },
            focus() { document.activeElement = this; },
            remove() { details.splice(details.indexOf(this), 1); },
        };
    }
    const container = { append(detail) { if (!details.includes(detail)) details.push(detail); },
        querySelector: () => details[0] ?? null, querySelectorAll: () => details };
    const input = Object.assign(node(), { name, id: 'monitoring-' + name, type: 'text', value: 'Retained draft', disabled: false,
        closest(selector) { return selector === '.field' ? container : null; } });
    const title = node(), summary = node();
    const form = { elements: [input],
        querySelector: () => summary,
        querySelectorAll(selector) {
            if (selector === '[data-field-error]') return details.filter(detail => Object.hasOwn(detail.dataset, 'fieldError'));
            if (selector === '[aria-invalid]' || selector === '[aria-invalid="true"]') return input.getAttribute('aria-invalid') ? [input] : [];
            return [input];
        } };
    Object.assign(document, { getElementById(id) { return id === 'monitoring-editor-title' ? title : details.find(detail => detail.id === id); },
        createElement() { const detail = node(); detail.classList.owner = detail; return detail; } });
    const production = { dataset: { prefix: 'production[0]' }, querySelector: () => input };
    const context = { document, form, restore: true, errors: { [name]: ['Review this field.'] }, activeSection: null,
        rootRows: kind => kind === 'production' ? [production] : [], revealIncome() {}, activateTab() {} };
    runInNewContext(feedback, context);
    return { input, context, document, details,
        restore() { runInNewContext(restoration, context); },
        shared() { context.applyFormErrors(form, context.errors); },
        clear() { runInNewContext(clearing, context); },
        references() { return (input.getAttribute('aria-describedby') ?? '').split(/\s+/).filter(Boolean); } };
}

test('initial feedback and Monitoring restoration reuse the same node and ID when shared feedback runs first', () => {
    const f = fixture();
    f.shared();
    const initial = f.details[0], id = initial.id;
    f.clear();
    f.restore();
    assert.equal(f.details.length, 1);
    assert.equal(f.details[0], initial);
    assert.equal(initial.id, id);
    assert.equal(initial.textContent, 'Review this field.');
    assert.deepEqual(f.references(), [id]);
    assert.equal(f.document.activeElement, f.input);
    assert.equal(f.input.value, 'Retained draft');
});

test('shared feedback reuses Monitoring feedback when the editor runs first', () => {
    const f = fixture();
    f.restore();
    const initial = f.details[0];
    f.shared(); f.shared();
    assert.equal(f.details.length, 1);
    assert.equal(f.details[0], initial);
    assert.deepEqual(f.references(), [initial.id]);
});

test('repeated restoration replaces text without duplicating nodes or accessible references', () => {
    const f = fixture();
    f.restore();
    const initial = f.details[0];
    f.context.errors.overall_remarks = ['Updated message.', '<b>Literal text.</b>'];
    f.restore(); f.shared(); f.restore();
    assert.equal(f.details.length, 1);
    assert.equal(f.details[0], initial);
    assert.equal(initial.textContent, 'Updated message. <b>Literal text.</b>');
    assert.deepEqual(f.references(), [initial.id]);
});

test('restoration reveals and updates an existing field error while retaining helper descriptions', () => {
    const f = fixture();
    f.shared();
    const initial = f.details[0];
    initial.hidden = true;
    f.input.setAttribute('aria-describedby', 'field-help  ' + initial.id + ' ' + initial.id);
    f.context.errors.overall_remarks = ['Replacement message.'];
    f.restore();
    assert.equal(f.details.length, 1);
    assert.equal(initial.hidden, false);
    assert.equal(initial.textContent, 'Replacement message.');
    assert.deepEqual(f.references(), ['field-help', initial.id]);
});

test('distinct validation keys sharing a fallback control keep their separate valid messages', () => {
    const f = fixture('');
    f.context.errors = { production: ['Add Production.'], 'production.0.new_product': ['Review the new product.'] };
    f.restore();
    const ids = f.details.map(detail => detail.id);
    f.restore();
    assert.equal(f.details.length, 2);
    assert.deepEqual(f.details.map(detail => detail.textContent), ['Add Production.', 'Review the new product.']);
    assert.deepEqual(f.references(), ids);
    assert.equal(new Set(ids).size, 2);
});

test('reused feedback remains owned by Monitoring and clears with resolved draft errors', () => {
    const f = fixture('project_id');
    f.shared(); f.clear(); f.restore();
    f.clear();
    assert.equal(f.details.length, 0);
    assert.equal(f.input.getAttribute('aria-invalid'), null);
    assert.deepEqual(f.references(), ['monitoring-project-help']);
    assert.equal(f.input.value, 'Retained draft');
});

test('initialization without restoration creates no field messages and leaves valid input alone', () => {
    const f = fixture();
    f.context.restore = false;
    f.restore();
    assert.equal(f.details.length, 0);
    assert.equal(f.input.getAttribute('aria-invalid'), null);
    assert.equal(f.document.activeElement, null);
    assert.equal(f.input.value, 'Retained draft');
});
