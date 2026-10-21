import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const view = readFileSync(new URL('../../resources/views/auth/password-recovery.blade.php', import.meta.url), 'utf8');
const script = view.match(/<script\b(?:[^"'<>]|"[^"]*"|'[^']*')*>([\s\S]*?)<\/script>/)?.[1];
const hash = 'a'.repeat(56);

function page(fragment, state = 'verify', waitSeconds = 60) {
    const listeners = [];
    const field = { value: '' };
    const button = { disabled: true };
    const note = { hidden: false };
    const form = {
        dataset: { waitSeconds: String(waitSeconds) },
        elements: { token_hash: field },
        querySelector: () => button,
        setAttribute() {},
        addEventListener(_name, callback) { listeners.push(callback); },
    };
    const countdown = { textContent: '' };
    const signIn = { href: '/login' };
    const timers = [];
    const redirects = [];
    let now = 0;
    let ready;
    const document = {
        addEventListener(_name, callback) { ready = callback; },
        getElementById(id) {
            if (id === 'recovery-verification') return state === 'verify' ? form : null;
            if (id === 'recovery-link-note') return note;
            if (id === 'recovery-success') return state === 'success' ? {} : null;
            if (id === 'recovery-sign-in') return signIn;
            if (id === 'recovery-resend') return state === 'confirmation' ? form : null;
            if (id === 'recovery-countdown') return countdown;
            return null;
        },
        querySelectorAll: () => ['verify', 'confirmation'].includes(state) ? [form] : [],
    };
    const window = {
        location: { hash: fragment, pathname: '/password-recovery', replace: (path) => redirects.push(path) },
        setTimeout(callback, delay) { timers.push({ callback, delay }); },
        history: { replaceState(_state, _title, path) {
            assert.equal(path, '/password-recovery');
            window.location.hash = '';
        } },
    };
    // This fixture exposes no network, browser, application or database.
    runInNewContext(script, { window, document, URLSearchParams, Date: { now: () => now } });
    return { window, field, button, note, countdown, timers, redirects, ready: () => ready(), advance(ms) {
        now += ms;
        timers.shift()?.callback();
    }, submit() {
        const event = { prevented: false, preventDefault() { this.prevented = true; } };
        for (const listener of listeners) listener(event);
        return event;
    } };
}

test('only the verified-success state schedules a sign-in redirect with a fallback target', () => {
    const success = page('', 'success');
    success.ready();
    assert.equal(success.timers.length, 1);
    assert.equal(success.timers[0].delay, 2500);
    assert.deepEqual(success.redirects, []);
    success.advance(2500);
    assert.deepEqual(success.redirects, ['/login']);
    for (const state of ['forgot', 'invalid', 'reset', 'verify']) {
        const fixture = page('', state);
        fixture.ready();
        assert.equal(fixture.timers.length, 0);
        assert.deepEqual(fixture.redirects, []);
    }
});

test('the resend countdown follows the server wait and catches up after a background-tab delay', () => {
    const fixture = page('', 'confirmation', 90);
    fixture.ready();
    assert.equal(fixture.button.disabled, true);
    assert.match(fixture.countdown.textContent, /90 seconds/);
    fixture.advance(89000);
    assert.equal(fixture.button.disabled, true);
    assert.match(fixture.countdown.textContent, /1 second\./);
    fixture.advance(1000);
    assert.equal(fixture.button.disabled, false);
    assert.match(fixture.countdown.textContent, /another link now/);
    assert.deepEqual(fixture.redirects, []);
});

test('the fragment is scrubbed immediately and handed to the form only on submission', () => {
    const fixture = page(`#token_hash=${hash}`);
    assert.equal(fixture.window.location.hash, '');
    assert.equal(fixture.field.value, '');
    fixture.ready();
    assert.equal(fixture.button.disabled, false);
    assert.equal(fixture.note.hidden, true);
    assert.equal(fixture.submit().prevented, false);
    assert.equal(fixture.field.value, hash);
    assert.equal(fixture.button.disabled, true);
    assert.equal(fixture.submit().prevented, true);
});

for (const fragment of ['', '#access_token=synthetic', '#token_hash=invalid', `#token_hash=${hash}%0A`,
    `#token_hash=${hash}&token_hash=${hash}`]) {
    test(`unusable fragment ${fragment.slice(0, 20)} cannot be submitted`, () => {
        const fixture = page(fragment);
        fixture.ready();
        assert.equal(fixture.window.location.hash, '');
        assert.equal(fixture.button.disabled, true);
        assert.equal(fixture.note.hidden, false);
        assert.equal(fixture.submit().prevented, true);
        assert.equal(fixture.field.value, '');
    });
}
