import assert from 'node:assert/strict';
import test from 'node:test';
import { initAssociationUi } from '../../resources/js/pages/admin/associations.js';
import { initDisclosures } from '../../resources/js/core/helpers.js';

function emitter(extra = {}) {
    const handlers = new Map();
    return Object.assign(extra, {
        handlers,
        addEventListener(type, listener) {
            const entries = handlers.get(type) ?? [];
            entries.push(listener);
            handlers.set(type, entries);
        },
        fire(type, event = {}) { for (const handler of handlers.get(type) ?? []) handler(event); },
    });
}

function fixture({ errors = false, dialogOpen = false, hasForm = true } = {}) {
    const heading = { focused: false, focus() { this.focused = true; } };
    const root = { dataset: {}, querySelectorAll: () => [], querySelector: () => heading };
    const dialog = emitter({ open: dialogOpen, shows: 0, showModal() { this.open = true; this.shows++; } });
    const form = { dataset: { hasErrors: String(errors) }, closest: () => dialog };
    const document = emitter({
        querySelector: selector => selector === '[data-association-auto-open="true"]' ? (hasForm ? form : null) : null,
    });
    const window = emitter({});
    globalThis.document = document;
    globalThis.window = window;
    return { heading, root, dialog, form, document, window };
}

test('other modules are untouched and association initialization binds once', () => {
    const { root, document } = fixture({ hasForm: false });
    initAssociationUi(null);
    assert.equal(document.handlers.size, 0);
    initAssociationUi(root);
    initAssociationUi(root);
    assert.equal(root.dataset.userMenusInitialized, 'true');
    assert.equal(document.handlers.get('click').length, 1);
    assert.equal(document.handlers.get('keydown').length, 1);
});

test('an explicitly requested editor opens once and restores focus to the current page heading', () => {
    const { root, dialog, heading } = fixture();
    initAssociationUi(root);
    initAssociationUi(root);
    assert.equal(dialog.shows, 1);
    dialog.fire('close');
    assert.equal(heading.focused, true);
});

test('validation recovery retains global feedback priority and does not open a second dialog', () => {
    const { root, dialog } = fixture({ errors: true });
    initAssociationUi(root);
    assert.equal(dialog.shows, 0);
    const alreadyOpen = fixture({ dialogOpen: true });
    initAssociationUi(alreadyOpen.root);
    assert.equal(alreadyOpen.dialog.shows, 0);
});

test('the shared filter disclosure updates aria-expanded and keeps selected filters while hidden', () => {
    const { document } = fixture({ hasForm: false });
    const selected = { status: 'inactive', sort: 'za', municipality: 'Stored municipality' };
    const panel = { hidden: true, selected };
    const attributes = { 'aria-expanded': 'false' };
    const button = {
        dataset: { disclosure: 'association-filter-panel' },
        getAttribute: name => attributes[name],
        setAttribute: (name, value) => { attributes[name] = value; },
    };
    document.getElementById = id => id === 'association-filter-panel' ? panel : null;
    initDisclosures();
    document.fire('click', { target: { closest: () => button } });
    assert.equal(panel.hidden, false);
    assert.equal(attributes['aria-expanded'], 'true');
    document.fire('click', { target: { closest: () => button } });
    assert.equal(panel.hidden, true);
    assert.equal(attributes['aria-expanded'], 'false');
    assert.deepEqual(panel.selected, selected);
});
