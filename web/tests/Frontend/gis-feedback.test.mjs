import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const read = path => readFileSync(new URL('../../resources/js/' + path, import.meta.url), 'utf8').replace(/\r\n/g, '\n');
const workspace = read('pages/gis-workspace.js'), editor = read('pages/gis-editor.js');
const mutation = workspace.match(/^        dialog.querySelector\('\[data-gis-confirm-submit\]'\).addEventListener\('click', async event => \{[\s\S]*?^        \}\);/m)[0];
const save = editor.match(/^    form.addEventListener\('submit', async event => \{[\s\S]*?^    \}\);/m)[0];
const load = workspace.match(/^    async function load\([\s\S]*?^    \}/m)[0];
const api = read('core/gis-api.js').replace(/^export /gm, '');

function mutationFixture(action = 'publish', { status, result, refreshed = true, refreshThrows = false, delay } = {}) {
    const outcomes = [], errors = [], requests = [], events = [];
    const button = { disabled: false }, cancel = {}, statusNode = { dataset: {} };
    let handler;
    const dialog = { open: true, querySelector: selector => selector === '[data-gis-confirm-submit]' ? { addEventListener: (name, fn) => { handler = fn; } } : cancel,
        close() { this.open = false; events.push('confirmation closed'); } };
    const form = {};
    const context = { encodeURIComponent, dialog, form, status: statusNode, base: '/admin/gis',
        pendingAction: { action, feature: { id: 'synthetic-id', properties: { version: 'old-version' } } },
        requestController: undefined, optionController: undefined, mutating: false, opener: {},
        root: { querySelector: selector => selector === '[data-gis-csrf]' ? { value: 'synthetic-csrf' } : { replaceChildren() {} } },
        gisRequest: async (url, options) => {
            requests.push({ url, options }); if (delay) await delay;
            if (status) { const error = new Error('Synthetic safe error'); error.status = status; throw error; }
            return result ?? { type: 'Feature', id: 'synthetic-id', properties: { is_published: true, archived_at: null } };
        }, clearSelection() {}, features: { clearLayers() {} },
        load: async () => { if (refreshThrows) throw new Error('Refresh unavailable'); return refreshed; },
        showStatus: (node, message) => { node.textContent = message; },
        showFeedback: value => { events.push('feedback'); outcomes.push(value); assert.equal(dialog.open, false); },
        showOperationError: error => { events.push('feedback'); errors.push(error); assert.equal(dialog.open, false); } };
    runInNewContext(mutation, context);
    return { context, requests, outcomes, errors, events, button, cancel, form, dialog, click: () => handler({ currentTarget: button }) };
}

test('GIS mutations confirm once, close confirmation before feedback, and preserve method/version/CSRF', async () => {
    for (const action of ['publish', 'unpublish', 'archive', 'delete']) {
        const f = mutationFixture(action); await f.click();
        assert.equal(f.outcomes.length, 1); assert.equal(f.errors.length, 0);
        assert.equal(f.requests[0].options.method, action === 'delete' ? 'DELETE' : 'PATCH');
        assert.equal(f.requests[0].options.body.version, 'old-version'); assert.equal(f.requests[0].options.csrf, 'synthetic-csrf');
        if (action === 'delete') assert.equal(f.requests[0].options.body.confirmation, true);
        assert.equal(f.form.inert, false); assert.equal(f.button.disabled, false); assert.equal(f.cancel.disabled, false);
        assert.deepEqual(f.events, ['confirmation closed', 'feedback']);
    }
});

test('GIS failures and unconfirmed publication never report success; saved mutation with failed refresh stays truthful', async () => {
    for (const status of [403, 409, 422, 429, 500, 503]) {
        const f = mutationFixture('publish', { status }); await f.click();
        assert.equal(f.errors[0].status, status); assert.equal(f.outcomes.length, 0);
    }
    const malformed = mutationFixture('publish', { result: { type: 'Feature', id: 'wrong-record' } }); await malformed.click();
    assert.equal(malformed.outcomes.length, 0); assert.equal(malformed.errors.length, 1);
    for (const action of ['publish', 'archive']) {
        for (const options of [{ refreshed: false }, { refreshThrows: true }]) {
            const f = mutationFixture(action, options); await f.click();
            assert.equal(f.errors.length, 0); assert.equal(f.outcomes.length, 1);
            assert.match(f.outcomes[0].message, /could not refresh/);
        }
    }
});

test('GIS duplicate mutation clicks permit one pending request', async () => {
    let resolve;
    const f = mutationFixture('publish', { delay: new Promise(done => { resolve = done; }) });
    const first = f.click(); await f.click(); assert.equal(f.requests.length, 1); resolve(); await first;
    assert.equal(f.outcomes.length, 1);
});

test('actual GIS editor save waits for acknowledgement before navigation and keeps drafts on failure', async () => {
    for (const status of [200, 403, 422, 409, 429, 500, 503, 0]) {
        const outcomes = [], errors = [], requests = [], navigation = [], input = { value: 'unsaved geometry' };
        let handler, acknowledge;
        const form = { action: '/admin/gis', elements: { _token: { value: 'synthetic-csrf' } }, reportValidity: () => true,
            querySelectorAll: () => [], addEventListener: (name, fn) => { handler = fn; } };
        const context = { form, saving: false, associationReady: true, projectState: 'ready', seed: null,
            validateGeometry: value => value, json: { value: '{"type":"Point","coordinates":[124,9]}' }, boundary: () => false,
            FormData: class { *[Symbol.iterator]() { yield ['_token', 'synthetic-csrf']; yield ['remarks', input.value]; } },
            association: { value: 'association-id' }, project: { value: 'project-id' },
            associationController: undefined, projectController: undefined, stopGeometryModes() {}, save: {}, status: { dataset: {} }, dirty: true,
            gisRequest: async (url, options) => { requests.push({ url, options }); if (status !== 200) {
                const error = new Error('Synthetic failure'); if (status) error.status = status; throw error;
            } return { type: 'Feature', id: 'synthetic-id' }; },
            showFeedback: result => { outcomes.push(result); return new Promise(done => { acknowledge = done; }); },
            showOperationError: error => errors.push(error), applyFormErrors() {}, showStatus() {}, updateSaveState() {},
            document: { querySelector: () => ({}) }, base: '/admin/gis', window: { location: { assign: url => navigation.push(url) } } };
        runInNewContext(save, context);
        const pending = handler({ preventDefault() {} }); await new Promise(setImmediate);
        if (status === 200) {
            assert.equal(outcomes.length, 1); assert.equal(navigation.length, 0); acknowledge(); await pending;
            assert.deepEqual(navigation, ['/admin/gis']); assert.equal(context.dirty, false);
        } else { await pending; assert.equal(errors.length, 1); assert.equal(outcomes.length, 0); assert.equal(context.dirty, true); }
        assert.equal(input.value, 'unsaved geometry'); assert.equal(form.inert, false);
        assert.equal(requests[0].options.method, 'POST'); assert.equal(requests[0].options.body._token, undefined);
    }
});

test('passive GIS load failures stay inline; explicit retry can request one error modal', async () => {
    const errors = [];
    const context = { mutating: false, viewportTimer: undefined, searchTimer: undefined, clearTimeout() {}, AbortController,
        requestController: undefined, view: 'list', filters: () => ({}), retryFit: false, retry: {}, status: { dataset: {} },
        root: { setAttribute() {}, removeAttribute() {}, querySelector: () => ({ replaceChildren() {} }) },
        dataUrl: () => '/gis/data', gisRequest: async () => { const error = new Error('Synthetic'); error.status = 503; throw error; },
        features: { clearLayers() {} }, selection: { clearLayers() {} }, clearSelection() {}, showStatus() {},
        updateFilterSummary() {}, showOperationError: error => errors.push(error) };
    runInNewContext(load, context);
    assert.equal(await context.load(), false); assert.equal(errors.length, 0);
    assert.equal(await context.load({ notifyFailure: true }), false); assert.equal(errors.length, 1);
});

test('pending editor saves reject duplicate submissions and retain the original version on conflict', async () => {
    let handler, reject;
    const calls = [], json = { value: '{"type":"Point","coordinates":[124,10]}' };
    const form = { action: '/admin/gis/saved-id', dataset: {}, elements: { _token: { value: 'synthetic-csrf' },
        version: { value: 'original-version' }, namedItem: () => null }, reportValidity: () => true,
        querySelectorAll: () => [], addEventListener: (name, fn) => { handler = fn; } };
    const context = { form, json, saving: false, associationReady: true, projectState: 'ready', seed: { id: 'saved-id' }, dirty: true,
        validateGeometry: value => value, boundary: () => false,
        FormData: class { *[Symbol.iterator]() { yield ['version', form.elements.version.value]; yield ['location_name', 'Unsaved name']; } },
        association: { value: 'association-id' }, project: { value: 'project-id' }, save: {}, status: { dataset: {} },
        associationController: undefined, projectController: undefined, stopGeometryModes() {}, updateSaveState() {},
        gisRequest: (url, options) => { calls.push(options); return new Promise((resolve, fail) => { reject = fail; }); },
        applyFormErrors() {}, showStatus() {}, showOperationError() {},
        showFeedback: () => assert.fail('A pending or rejected save must not report success.'),
        window: { location: { assign: () => assert.fail('A conflict must preserve the editor.') } }, document: { querySelector: () => null } };
    runInNewContext(save, context);
    const pending = handler({ preventDefault() {} });
    await handler({ preventDefault() {} });
    assert.equal(calls.length, 1); assert.equal(form.inert, true);
    const error = new Error('Synthetic version conflict'); error.status = 409;
    reject(error); await pending;
    assert.equal(form.elements.version.value, 'original-version');
    assert.equal(json.value, '{"type":"Point","coordinates":[124,10]}');
    assert.equal(calls[0].body.location_name, 'Unsaved name');
    assert.equal(context.dirty, true); assert.equal(form.inert, false); assert.equal(context.saving, false);
});

test('GIS request helper preserves status, same-origin/session/CSRF, rejects HTML and malformed successes', async () => {
    const calls = [], context = { URL, window: { location: { origin: 'https://assocmap.test' } },
        fetch: async (url, options) => { calls.push(options); return context.response; } };
    runInNewContext(api, context);
    for (const status of [403, 422, 429, 500, 503]) {
        context.response = { status, ok: false, headers: { get: () => 'text/html' } };
        await assert.rejects(context.gisRequest('/gis'), error => error.status === status);
    }
    context.response = { status: 200, ok: true, headers: { get: () => 'application/json' }, json: async () => null };
    await assert.rejects(context.gisRequest('/gis'), error => error.status === 503);
    context.response.json = async () => ({ type: 'Feature' }); await context.gisRequest('/gis', { method: 'PATCH', csrf: 'synthetic', body: {} });
    assert.equal(calls.at(-1).method, 'PATCH'); assert.equal(calls.at(-1).headers['X-CSRF-TOKEN'], 'synthetic');
    assert.equal(calls.at(-1).credentials, 'same-origin'); assert.equal(calls.at(-1).redirect, 'error');
    await assert.rejects(context.gisRequest('https://foreign.test/gis')); assert.equal(calls.length, 7);
});

test('marked GIS workspace/editor initialization cannot attach another handler', async () => {
    for (const source of [workspace, editor]) {
        const context = {};
        runInNewContext(source.replace(/^import .*\n/gm, '').replace(/^export /gm, ''), context);
        await context.initGis({ dataset: { gisInitialized: 'true' } });
    }
});
