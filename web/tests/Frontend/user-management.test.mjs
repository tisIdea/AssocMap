import test from 'node:test';
import assert from 'node:assert/strict';
import { initUserMenus } from '../../resources/js/pages/admin/users.js';

function events(object = {}) {
    const handlers = new Map();
    return Object.assign(object, {
        handlers,
        addEventListener(type, handler, capture) {
            const entries = handlers.get(type) ?? [];
            entries.push({ handler, capture });
            handlers.set(type, entries);
        },
        dispatch(type, event = {}) {
            for (const { handler } of handlers.get(type) ?? []) handler(event);
        },
    });
}

function fixture() {
    const menus = [0, 1].map(() => {
        const summary = {
            focused: false,
            focus() { this.focused = true; },
            getBoundingClientRect: () => ({ right: 460, bottom: 560, top: 524 }),
        };
        const panel = { offsetWidth: 260, offsetHeight: 240, style: {} };
        return events({
            open: false,
            summary,
            panel,
            querySelector: selector => selector === 'summary' ? summary : panel,
            contains(target) { return target === this || target === summary || target === panel; },
        });
    });
    const root = { dataset: {}, querySelectorAll: () => menus };
    const document = events({
        dialogOpen: false,
        querySelector(selector) { return selector === 'dialog[open]' ? this.dialogOpen : root; },
    });
    const window = events({ innerWidth: 480, innerHeight: 600 });
    globalThis.document = document;
    globalThis.window = window;
    return { root, menus, document, window, open(menu) { menu.open = true; menu.dispatch('toggle'); } };
}

test('initialization is scoped, safe without a register, and idempotent', () => {
    const { root, document } = fixture();
    initUserMenus(null);
    assert.equal(document.handlers.size, 0);
    initUserMenus(root);
    initUserMenus(root);
    assert.equal(document.handlers.get('click').length, 1);
    assert.equal(document.handlers.get('keydown').length, 1);
});

test('opening a menu closes its sibling and keeps the panel inside a narrow viewport', () => {
    const { root, menus, open } = fixture();
    initUserMenus(root);
    open(menus[0]);
    open(menus[1]);
    assert.equal(menus[0].open, false);
    assert.equal(menus[1].open, true);
    assert.equal(menus[1].panel.style.left, '200px');
    assert.equal(menus[1].panel.style.top, '276px');
    menus[1].summary.getBoundingClientRect = () => ({ right: 20, bottom: 48, top: 12 });
    menus[1].dispatch('toggle');
    assert.equal(menus[1].panel.style.left, '12px');
    assert.equal(menus[1].panel.style.top, '56px');
});

test('outside clicks close the menu without stealing focus; inside clicks leave it open', () => {
    const { root, menus, document, open } = fixture();
    initUserMenus(root);
    open(menus[0]);
    document.dispatch('click', { target: menus[0].panel });
    assert.equal(menus[0].open, true);
    document.dispatch('click', { target: {} });
    assert.equal(menus[0].open, false);
    assert.equal(menus[0].summary.focused, false);
});

test('Escape closes the disclosure and restores focus without reaching shared shell handlers', () => {
    const { root, menus, document, open } = fixture();
    initUserMenus(root);
    open(menus[0]);
    let prevented = false;
    let stopped = false;
    document.dispatch('keydown', {
        key: 'Escape',
        preventDefault() { prevented = true; },
        stopPropagation() { stopped = true; },
    });
    assert.equal(document.handlers.get('keydown')[0].capture, true);
    assert.equal(menus[0].open, false);
    assert.equal(menus[0].summary.focused, true);
    assert.equal(prevented, true);
    assert.equal(stopped, true);
});

test('a live dialog retains Escape priority and other keys keep native keyboard behavior', () => {
    const { root, menus, document, open } = fixture();
    initUserMenus(root);
    open(menus[0]);
    document.dialogOpen = true;
    document.dispatch('keydown', { key: 'Escape' });
    document.dialogOpen = false;
    document.dispatch('keydown', { key: 'Tab' });
    assert.equal(menus[0].open, true);
});

test('viewport resize and scrolling dismiss floating menus', () => {
    const { root, menus, window, open } = fixture();
    initUserMenus(root);
    for (const type of ['resize', 'scroll']) {
        open(menus[0]);
        window.dispatch(type);
        assert.equal(menus[0].open, false);
    }
    assert.equal(window.handlers.get('scroll')[0].capture, true);
});
