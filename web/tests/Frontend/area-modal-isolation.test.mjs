import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/pages/admin/forms.js', import.meta.url), 'utf8').replace(/\r\n/g, '\n');
const binding = source.match(/^    \$\$\('\[data-form\]'\)\.forEach\(form => \{[\s\S]*?^    \}\);/m)?.[0];
assert.ok(binding);

test('only an Area form with its own restored errors opens, and reinitialization binds once', () => {
    for (const hasErrors of [false, true]) {
        const handlers = {}, dialog = { dataset: {}, shows: 0, showModal() { this.shows++; } };
        const form = { dataset: { hasErrors: String(hasErrors) },
            matches: selector => selector.includes('[data-area-form]'),
            addEventListener: (name, callback) => { (handlers[name] ??= []).push(callback); }, closest: () => dialog };
        const context = { $$: () => [form], syncForm() {} };
        runInNewContext(binding, context); runInNewContext(binding, context);
        assert.equal(dialog.shows, 0);
        assert.equal(dialog.dataset.feedbackRestore, hasErrors ? 'true' : undefined);
        assert.equal(handlers.submit.length, 1);
        assert.equal(handlers.change.length, 1);
        assert.equal(handlers.reset.length, 1);
    }
});

test('Specific Area submission retains its type so missing-parent errors restore the right form', () => {
    const handlers = {};
    const type = { value: 'Specific Area', disabled: false };
    const form = { dataset: {}, matches: selector => selector.includes('[data-area-form]'),
        addEventListener: (name, callback) => { handlers[name] = callback; } };
    runInNewContext(binding, { $$: () => [form], $: () => type, syncForm() {} });
    handlers.submit({ preventDefault() { assert.fail('Native Area submission must be preserved'); } });
    assert.equal(type.disabled, false);
});
