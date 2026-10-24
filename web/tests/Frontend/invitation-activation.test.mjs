import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const layout = readFileSync(new URL('../../resources/views/auth/invitation-layout.blade.php', import.meta.url), 'utf8');
// Blade's PHP arrows inside quoted attributes do not end the opening tag.
const script = layout.match(/<script\b(?:[^"'<>]|"[^"]*"|'[^']*')*>([\s\S]*?)<\/script>/)?.[1];
assert.ok(script, 'The activation layout must contain its inline initializer.');
assert.ok(!script.includes('{{') && !script.includes('{!!'), 'The initializer must not contain unresolved Blade expressions.');
const syntheticHash = 'a'.repeat(56);

function eventTarget() {
    const listeners = new Map();
    return {
        addEventListener(name, callback, options = {}) {
            const entries = listeners.get(name) ?? [];
            entries.push({ callback, once: options.once === true });
            listeners.set(name, entries);
        },
        dispatch(name, event = {}) {
            for (const entry of [...(listeners.get(name) ?? [])]) {
                if (entry.once) {
                    listeners.set(name, listeners.get(name).filter((item) => item !== entry));
                }
                entry.callback(event);
            }
        },
        listenerCount(name) {
            return (listeners.get(name) ?? []).length;
        },
    };
}

function page(readyState = 'loading', hash = `#token_hash=${syntheticHash}`, pathname = '/invitation', invalidMessage = null) {
    const field = { value: '' };
    const button = { disabled: true, textContent: 'Verify invitation' };
    const attributes = {};
    const note = {
        hidden: false,
        textContent: 'Initialization has not run.',
        getAttribute(name) { return name === 'data-invalid-message' ? invalidMessage : null; },
        setAttribute(name, value) { attributes[`note:${name}`] = value; },
    };
    const form = {
        ...eventTarget(),
        elements: { token_hash: field },
        querySelector: () => button,
        setAttribute(name, value) { attributes[`form:${name}`] = value; },
    };
    const document = {
        ...eventTarget(),
        readyState,
        getElementById(id) {
            return id === 'invitation-verification' ? form : id === 'invitation-link-note' ? note : null;
        },
        querySelectorAll: () => [form],
    };
    const window = {
        ...eventTarget(),
        location: { hash, pathname },
        history: {
            replaceState(_state, _title, path) {
                assert.equal(path, pathname);
                window.location.hash = '';
            },
        },
    };

    // No network, browser, application bootstrap, or database is available here.
    runInNewContext(script, { window, document, URLSearchParams });
    return {
        button, field, note, form, window, attributes,
        ready() {
            document.readyState = 'interactive';
            document.dispatch('DOMContentLoaded');
            window.dispatch('DOMContentLoaded');
        },
        submit() {
            const event = { prevented: false, preventDefault() { this.prevented = true; } };
            form.dispatch('submit', event);
            return event;
        },
    };
}

test('initialization waits for parsing, scrubs the fragment, then enables a valid invitation', () => {
    const fixture = page();
    assert.equal(fixture.window.location.hash, '');
    assert.equal(fixture.button.disabled, true);
    fixture.ready();
    assert.equal(fixture.button.disabled, false);
    assert.equal(fixture.note.hidden, true);
    assert.equal(fixture.field.value, '');
    assert.equal(fixture.attributes['form:aria-busy'], undefined);
});

test('activation restart scrubs its proof while retaining the callback path and explicit submission', () => {
    const path = `/invitation/restart/${'R'.repeat(64)}`;
    const fixture = page('complete', `#token_hash=${syntheticHash}`, path);
    assert.equal(fixture.window.location.hash, '');
    assert.equal(fixture.window.location.pathname, path);
    assert.equal(fixture.field.value, '');
    assert.equal(fixture.button.disabled, false);
    assert.equal(fixture.submit().prevented, false);
    assert.equal(fixture.field.value, syntheticHash);
    assert.equal(fixture.submit().prevented, true);
});

test('activation restart invalid proof directs the recipient to the latest recovery email', () => {
    const message = 'Reopen the latest activation recovery email or contact an Administrator.';
    const fixture = page('complete', '#access_token=unused', '/invitation/restart/reference', message);
    assert.equal(fixture.note.textContent, message);
    assert.equal(fixture.button.disabled, true);
    assert.equal(fixture.field.value, '');
});

for (const state of ['interactive', 'complete']) {
    test(`late initialization enables a valid invitation when the DOM is ${state}`, () => {
        const fixture = page(state);
        assert.equal(fixture.button.disabled, false);
        assert.equal(fixture.note.hidden, true);
    });
}

test('readiness initialization installs only one submit listener', () => {
    const fixture = page();
    fixture.ready();
    fixture.ready();
    assert.equal(fixture.form.listenerCount('submit'), 1);
});

for (const [name, hash] of [
    ['missing', ''],
    ['malformed', '#token_hash=not-a-valid-hash'],
    ['short', `#token_hash=${'a'.repeat(55)}`],
    ['long', `#token_hash=${'a'.repeat(57)}`],
    ['unsupported SHA-256 length', `#token_hash=${'a'.repeat(64)}`],
    ['trailing newline', `#token_hash=${syntheticHash}%0A`],
    ['wrong token type', '#access_token=synthetic-unused-value'],
    ['ambiguous', `#token_hash=${syntheticHash}&token_hash=${syntheticHash}`],
]) {
    test(`${name} token keeps verification disabled with recovery guidance`, () => {
        const fixture = page('complete', hash);
        assert.equal(fixture.button.disabled, true);
        assert.equal(fixture.note.hidden, false);
        assert.match(fixture.note.textContent, /no usable invitation token/);
        assert.match(fixture.note.textContent, /contact the administrator/);
        assert.equal(fixture.attributes['note:role'], 'alert');
        assert.equal(fixture.attributes['form:aria-busy'], undefined);
        assert.equal(fixture.submit().prevented, true);
        assert.equal(fixture.field.value, '');
    });
}

test('submission transfers the token once and enters the pending state only on submit', () => {
    const fixture = page('complete');
    assert.equal(fixture.submit().prevented, false);
    assert.ok(fixture.field.value === syntheticHash);
    assert.equal(fixture.button.disabled, true);
    assert.equal(fixture.button.textContent, 'Verifying…');
    assert.equal(fixture.attributes['form:aria-busy'], 'true');
    assert.equal(fixture.submit().prevented, true);
});

function passwordPage(readyState = 'loading') {
    const fields = ['activation-password', 'activation-confirmation'].map((id) => ({
        id, type: 'password', value: 'Synthetic-Password42!', required: true, minLength: 12, maxLength: 128,
    }));
    const toggles = fields.map((field, index) => {
        const label = index === 0 ? 'new password' : 'confirm password';
        const attributes = { 'aria-controls': field.id, 'data-password-label': label, 'aria-label': `Show ${label}` };
        const text = { textContent: 'Show' };
        const eyeOff = {
            hidden: true,
            toggleAttribute(name, hidden) {
                assert.equal(name, 'hidden');
                this.hidden = hidden;
                return hidden;
            },
        };
        return {
            ...eventTarget(), hidden: true, attributes, text, eyeOff,
            getAttribute: (name) => attributes[name],
            setAttribute(name, value) { attributes[name] = value; },
            querySelector(selector) {
                return selector === '[data-password-toggle-text]' ? text : eyeOff;
            },
        };
    });
    const submitButton = { disabled: false, textContent: 'Activate account' };
    let submissions = 0;
    const form = { ...eventTarget(), querySelector: () => submitButton };
    form.addEventListener('submit', () => submissions++);
    const document = {
        ...eventTarget(), readyState,
        getElementById: (id) => fields.find((field) => field.id === id) ?? null,
        querySelectorAll(selector) {
            return selector === '[data-invitation-password-toggle]' ? toggles : selector === 'form' ? [form] : [];
        },
    };
    const window = {
        location: { hash: '', pathname: '/invitation/password' },
        history: { replaceState() {} },
    };
    runInNewContext(script, { window, document, URLSearchParams });
    return {
        fields, toggles, form, submitButton,
        submissions: () => submissions,
        ready() {
            document.readyState = 'interactive';
            document.dispatch('DOMContentLoaded');
        },
    };
}

test('password fields are hidden by default and controls appear after initialization', () => {
    const fixture = passwordPage();
    assert.ok(fixture.fields.every((field) => field.type === 'password'));
    assert.ok(fixture.toggles.every((toggle) => toggle.hidden));
    fixture.ready();
    assert.ok(fixture.fields.every((field) => field.type === 'password'));
    assert.ok(fixture.toggles.every((toggle) => !toggle.hidden));
});

for (const [index, label] of [[0, 'new password'], [1, 'confirm password']]) {
    test(`${label} visibility toggles independently with matching accessible labels and icons`, () => {
        const fixture = passwordPage('complete');
        const toggle = fixture.toggles[index];
        toggle.dispatch('click');
        assert.equal(fixture.fields[index].type, 'text');
        assert.equal(fixture.fields[1 - index].type, 'password');
        assert.equal(toggle.attributes['aria-label'], `Hide ${label}`);
        assert.equal(toggle.text.textContent, 'Hide');
        assert.equal(toggle.eyeOff.hidden, false);
        toggle.dispatch('click');
        assert.equal(fixture.fields[index].type, 'password');
        assert.equal(toggle.attributes['aria-label'], `Show ${label}`);
        assert.equal(toggle.text.textContent, 'Show');
        assert.equal(toggle.eyeOff.hidden, true);
    });
}

test('visibility toggles preserve typed values and constraints without submitting or entering a loading state', () => {
    const fixture = passwordPage('complete');
    const values = fixture.fields.map((field) => field.value);
    fixture.toggles.forEach((toggle) => toggle.dispatch('click'));
    assert.ok(fixture.fields.every((field, index) => field.value === values[index]));
    assert.ok(fixture.fields.every((field) => field.required && field.minLength === 12 && field.maxLength === 128));
    assert.equal(fixture.submissions(), 0);
    assert.equal(fixture.submitButton.disabled, false);
    assert.equal(fixture.submitButton.textContent, 'Activate account');
    fixture.form.dispatch('submit');
    assert.equal(fixture.submissions(), 1);
    assert.equal(fixture.submitButton.disabled, true);
    assert.equal(fixture.submitButton.textContent, 'Please wait…');
});

test('password readiness initialization installs only one listener per control', () => {
    const fixture = passwordPage();
    fixture.ready();
    fixture.ready();
    assert.ok(fixture.toggles.every((toggle) => toggle.listenerCount('click') === 1));
});
