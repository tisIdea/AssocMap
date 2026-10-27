import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const read = path => readFileSync(new URL('../../resources/js/pages/' + path, import.meta.url), 'utf8').replace(/\r\n/g, '\n');
const editor = read('gis-editor.js');
const workspace = read('gis-workspace.js');
const save = editor.match(/^    form.addEventListener\('submit', async event => \{[\s\S]*?^    \}\);/m)[0];

test('correction submits geometry and version only, with PATCH and CSRF, then awaits acknowledgement', async () => {
    const requests = [], navigation = [];
    let handler, acknowledge;
    const form = { action: '/admin/gis/saved-id/coordinates', dataset: { gisCorrection: 'true' },
        elements: { _token: { value: 'synthetic-csrf' }, version: { value: 'saved-version' } },
        reportValidity: () => true, querySelectorAll: () => [], addEventListener: (name, listener) => { handler = listener; } };
    const context = { form, saving: false, associationReady: true, projectState: 'ready', seed: { id: 'saved-id' },
        validateGeometry: value => value, json: { value: '{"type":"Point","coordinates":[124,10]}' }, boundary: () => false,
        FormData: class { constructor() { throw new Error('Correction must not serialize unrelated form fields.'); } },
        associationController: undefined, projectController: undefined, stopGeometryModes() {},
        save: {}, status: { dataset: {} }, dirty: true,
        gisRequest: async (url, options) => { requests.push({ url, options }); return { type: 'Feature', id: 'saved-id' }; },
        showFeedback: () => new Promise(done => { acknowledge = done; }), showOperationError() {},
        applyFormErrors() {}, showStatus() {}, updateSaveState() {}, document: { querySelector: () => ({}) },
        base: '/admin/gis', window: { location: { assign: url => navigation.push(url) } } };
    runInNewContext(save, context);
    const pending = handler({ preventDefault() {} });
    await new Promise(setImmediate);
    assert.equal(requests[0].url, form.action);
    assert.equal(requests[0].options.method, 'PATCH');
    assert.equal(requests[0].options.csrf, 'synthetic-csrf');
    assert.deepEqual(Object.keys(requests[0].options.body).sort(), ['geometry', 'version']);
    assert.equal(requests[0].options.body.version, 'saved-version');
    assert.equal(navigation.length, 0);
    acknowledge(); await pending;
    assert.deepEqual(navigation, ['/admin/gis']);
    assert.equal(form.inert, false);
});

test('correction failures preserve geometry and the original version for review', async () => {
    for (const code of [403, 409, 422, 503]) {
        let handler;
        const errors = [], navigation = [];
        const json = { value: '{"type":"Point","coordinates":[124,10]}' };
        const form = { action: '/admin/gis/saved-id/coordinates', dataset: { gisCorrection: 'true' },
            elements: { _token: { value: 'synthetic-csrf' }, version: { value: 'original-version' } },
            reportValidity: () => true, querySelectorAll: () => [], addEventListener: (name, fn) => { handler = fn; } };
        const context = { form, saving: false, associationReady: true, projectState: 'ready', seed: { id: 'saved-id' },
            json, boundary: () => false, validateGeometry: value => value,
            associationController: undefined, projectController: undefined, stopGeometryModes() {}, save: {}, status: { dataset: {} }, dirty: true,
            gisRequest: async () => { const error = new Error('Synthetic rejection'); error.status = code; throw error; },
            showFeedback: () => assert.fail('A rejected correction must not report success.'),
            showOperationError: error => errors.push(error), applyFormErrors() {}, showStatus() {}, updateSaveState() {},
            document: { querySelector: () => ({}) }, base: '/admin/gis', window: { location: { assign: url => navigation.push(url) } } };
        runInNewContext(save, context);
        await handler({ preventDefault() {} });
        assert.equal(errors[0].status, code);
        assert.equal(context.dirty, true);
        assert.equal(json.value, '{"type":"Point","coordinates":[124,10]}');
        assert.equal(form.elements.version.value, 'original-version');
        assert.equal(navigation.length, 0);
        assert.equal(form.inert, false);
    }
});

test('historical parents expose correction without general edit, publication, archival or deletion controls', () => {
    const select = workspace.match(/^    function selectFeature\(feature\) \{[\s\S]*?^    \}/m)[0];
    const actionsSource = workspace.match(/^    function renderRecordActions\(feature, actions\) \{[\s\S]*?^    \}/m)[0];
    for (const parent of ['association_archived_at', 'project_archived_at']) {
        const actions = { children: [], replaceChildren() { this.children = []; }, append(child) { this.children.push(child); } };
        const details = { replaceChildren() {} };
        const root = { querySelector: selector => selector === '[data-gis-record-actions]' ? actions : ['[data-gis-details]', '[data-gis-history-details]'].includes(selector) ? details : {} };
        const context = { root, manager: true, publicView: false, base: '/admin/gis', selected: null, gallery: null,
            locationLabels: { project_point: 'Project point' }, addDetail() {}, actorName: value => value,
            confirmAction: () => assert.fail('No general history mutation should be exposed.'),
            document: { createElement: () => ({ addEventListener() {} }) } };
        runInNewContext(actionsSource + '\n' + select, context);
        context.selectFeature({ id: 'saved-id', geometry: null, properties: { location_type: 'project_point', [parent]: '2026-01-01', can_correct_geometry: true } });
        assert.deepEqual(actions.children.map(child => child.textContent), ['Correct geometry']);
        assert.equal(actions.children[0].href, '/admin/gis/saved-id/coordinates');
    }
});

test('correction initialization does not request parent options', async () => {
    const load = editor.match(/^    function loadParents\(\) \{.*\}/m)[0];
    let requests = 0;
    const context = { correcting: true, loadAssociations: () => { requests++; }, loadProjects: () => { requests++; } };
    runInNewContext(load, context);
    await context.loadParents(); assert.equal(requests, 0);
    context.correcting = false;
    await context.loadParents(); assert.equal(requests, 2);
});
