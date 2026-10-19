import assert from 'node:assert/strict';
import test from 'node:test';
import { initProgramActivities } from '../../resources/js/pages/program-activities.js';

function emitter(value = {}) {
    const handlers = new Map();
    return Object.assign(value, {
        handlers,
        addEventListener(type, listener) { handlers.set(type, [...(handlers.get(type) ?? []), listener]); },
        fire(type) { for (const listener of handlers.get(type) ?? []) listener(); },
    });
}

function fixture({ errors = false, dialogOpen = false, hasEditor = true } = {}) {
    const heading = { focused: false, focus() { this.focused = true; } };
    const root = { dataset: {}, querySelectorAll: () => [], querySelector: () => heading };
    const dialog = emitter({ dataset: {}, open: false, shows: 0, querySelector: () => errors,
        showModal() { this.open = true; this.shows++; },
    });
    const document = emitter({
        getElementById: () => hasEditor ? dialog : null,
        querySelector: () => dialogOpen ? {} : null,
    });
    globalThis.document = document;
    globalThis.window = emitter();
    return { root, dialog, document, heading };
}

test('Activity initialization is scoped and binds the existing menu handlers only once', () => {
    const { root, document } = fixture({ hasEditor: false });
    initProgramActivities(null);
    assert.equal(document.handlers.size, 0);
    initProgramActivities(root);
    initProgramActivities(root);
    assert.equal(root.dataset.userMenusInitialized, 'true');
    assert.equal(document.handlers.get('click').length, 1);
    assert.equal(document.handlers.get('keydown').length, 1);
});

test('an explicitly requested Activity editor opens once and returns focus to the heading', () => {
    const { root, dialog, heading } = fixture();
    initProgramActivities(root);
    initProgramActivities(root);
    assert.equal(dialog.shows, 1);
    dialog.fire('close');
    assert.equal(heading.focused, true);
});

test('Activity errors use the existing feedback restore flow without competing dialogs', () => {
    const { root, dialog } = fixture({ errors: true });
    initProgramActivities(root);
    assert.equal(dialog.shows, 0);
    assert.equal(dialog.dataset.feedbackRestore, 'true');
    const other = fixture({ dialogOpen: true });
    initProgramActivities(other.root);
    assert.equal(other.dialog.shows, 0);
});
