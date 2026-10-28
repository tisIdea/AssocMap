import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createContext, runInContext } from 'node:vm';

const read = path => readFileSync(new URL('../../resources/js/' + path, import.meta.url), 'utf8').replace(/\r\n/g, '\n');
const workspace = read('pages/gis-workspace.js');
const api = read('core/gis-api.js');
const editor = read('pages/gis-editor.js');
const settle = async () => { await new Promise(setImmediate); await new Promise(setImmediate); };
const feature = (id, properties = {}) => ({ type: 'Feature', id, geometry: { type: 'Point', coordinates: [123.8, 10.3] },
    properties: { title: id, location_type: 'project_point', archived_at: null, ...properties } });

class Element {
    constructor() { this.children = []; this.listeners = {}; this.dataset = {}; this.attributes = {}; this.value = ''; this.textContent = ''; this.hidden = false; this.disabled = false; this.classList = { toggle() {} }; }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = children; }
    setAttribute(key, value) { this.attributes[key] = value; }
    removeAttribute(key) { delete this.attributes[key]; }
    addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }
    fire(name, target = this) { for (const fn of this.listeners[name] || []) fn({ target, currentTarget: this, preventDefault() {} }); }
    focus() { this.focused = true; }
    matches(selector) { return selector === '[data-gis-option-search]' && this.optionSearch; }
}

async function fixture({ manager = false, source = workspace, request } = {}) {
    const nodes = new Map(), calls = [], timers = new Map(), groups = [], layerCallbacks = new Map();
    let timerId = 0;
    const node = selector => { if (!nodes.has(selector)) nodes.set(selector, new Element()); return nodes.get(selector); };
    const root = new Element(); root.dataset = { gisBase: '/admin/gis', gisPublic: 'false', gisManager: String(manager) };
    const form = node('[data-gis-filters]');
    form.elements = Object.fromEntries(['q', 'location_type', 'site_type_id', 'municipality_id', 'barangay_id', 'area_id', 'association_id', 'project_id', 'publication', 'lifecycle'].map(name => [name, new Element()]));
    form.elements.lifecycle.value = 'active';
    const parents = ['municipality', 'barangay', 'area', 'association', 'project'].map(kind => {
        const select = form.elements[kind + '_id']; select.dataset.gisParent = kind;
        nodes.set(`[data-gis-parent="${kind}"]`, select); return select;
    });
    const views = ['map', 'list'].map(view => { const button = node(`[data-gis-view="${view}"]`); button.dataset.gisView = view; return button; });
    const pages = ['previous', 'next'].map(page => { const button = node(`[data-gis-page="${page}"]`); button.dataset.gisPage = page; return button; });
    root.querySelector = selector => selector === '[data-gis-confirm]' ? null : !manager && selector === '[data-gis-history]' ? null : node(selector);
    root.querySelectorAll = selector => ({ '[data-gis-parent]': parents, '[data-gis-view]': views, '[data-gis-page]': pages }[selector] || []);
    node('[data-gis-option-search]').optionSearch = true;
    const map = { listeners: {}, on(name, fn) { this.listeners[name] = fn; }, fitBounds() {}, invalidateSize() {}, closePopup() {},
        getBounds: () => ({ getWest: () => 123, getSouth: () => 10, getEast: () => 124, getNorth: () => 11 }) };
    const defaultRequest = async url => {
        const kind = url.searchParams.get('kind');
        if (kind) return { options: [{ value: kind + '-1', label: kind + ' one' }], truncated: false };
        if (url.searchParams.get('mode') === 'overview') return { type: 'FeatureCollection', features: [], meta: { bounds: [123, 10, 124, 11] } };
        return { type: 'FeatureCollection', features: [feature('site-a'), feature('site-b')], meta: { page: 1, last_page: 1, total: 2 } };
    };
    const context = createContext({ root, URL, AbortController, console,
        window: { location: { origin: 'https://assocmap.test' } },
        document: { createElement: () => new Element() },
        FormData: class { *[Symbol.iterator]() { for (const [name, input] of Object.entries(form.elements)) yield [name, input.value]; } },
        setTimeout: (fn, delay) => { timers.set(++timerId, { fn, delay }); return timerId; }, clearTimeout: id => timers.delete(id),
        L: { featureGroup: () => { const group = { layers: [], addTo() { groups.push(this); return this; }, clearLayers() { this.layers = []; } }; return group; } },
        createGisMap: () => map, locationLabels: { project_point: 'Project point', project_boundary: 'Project boundary' },
        featureLayer: (item, select) => { layerCallbacks.set(item.id, () => select(item)); return { addTo(group) { group.layers.push(item); return this; } }; },
        fitLayer() {}, showFeedback() {}, showOperationError() {},
    });
    runInContext(api.replace(/^export /gm, ''), context);
    context.gisRequest = async (url, options) => {
        const target = new URL(url); calls.push({ url: target, options });
        return (request || defaultRequest)(target, options, defaultRequest);
    };
    context.fillOptions = (select, data) => { if (!data.options.some(option => option.value === select.value)) select.value = ''; };
    runInContext(source.replace(/^import .*\n/gm, '').replace(/^export /gm, ''), context);
    await context.initGis(root);
    return { root, form, node, calls, map, groups, context, layerCallbacks,
        async flushTimers(delay) { for (const [id, timer] of [...timers]) if (delay === undefined || timer.delay === delay) { timers.delete(id); timer.fn(); } await settle(); },
        async fire(selector, event, target) { node(selector).fire(event, target); await settle(); },
    };
}

test('map initialization, map/list switches, selection and dismiss have bounded request counts', async () => {
    const f = await fixture();
    assert.equal(f.calls.length, 7); // Five parent lookups, one extent and one bounded map response.
    assert.equal(f.calls.at(-1).url.searchParams.get('bbox[0]'), '123');
    await f.fire('[data-gis-view="map"]', 'click');
    assert.equal(f.calls.length, 7);
    f.layerCallbacks.get('site-a')();
    assert.equal(f.node('[data-gis-selection-title]').textContent, 'site-a');
    f.layerCallbacks.get('site-b')();
    assert.equal(f.node('[data-gis-selection-title]').textContent, 'site-b');
    assert.ok(!f.node('[data-gis-details]').children.some(row => row.children[1].textContent === 'site-a'));
    await f.fire('[data-gis-dismiss]', 'click');
    assert.equal(f.node('[data-gis-details]').children.length, 0);
    assert.equal(f.node('[data-gis-dismiss]').disabled, true);
    assert.equal(f.node('[data-gis-view="map"]').focused, true);
    assert.equal(f.calls.length, 7);
    await f.fire('[data-gis-view="list"]', 'click');
    assert.equal(f.calls.length, 8);
    assert.equal(f.node('[data-gis-map-panel]').hidden, true);
    assert.equal(f.node('[data-gis-list]').children.length, 2);
    const listSelectionRequests = f.calls.length;
    f.node('[data-gis-list]').children[1].children.find(child => child.textContent === 'View location').fire('click'); await settle();
    assert.equal(f.node('[data-gis-selection-title]').textContent, 'site-b');
    assert.equal(f.node('[data-gis-selection-title]').focused, true);
    assert.equal(f.calls.length, listSelectionRequests);
    await f.fire('[data-gis-view="list"]', 'click');
    await f.fire('[data-gis-view="map"]', 'click');
    assert.equal(f.calls.length, 11);
});

test('simple filters reuse parent options; active indicators and reset match submitted filters', async () => {
    const f = await fixture({ manager: true });
    f.form.elements.location_type.value = 'project_point';
    f.form.fire('change', f.form.elements.location_type); await settle();
    assert.equal(f.calls.length, 9);
    assert.equal(f.node('[data-gis-filter-summary]').textContent, '1 active filter');
    assert.equal(f.calls.at(-1).url.searchParams.get('location_type'), 'project_point');
    f.form.elements.publication.value = 'unpublished'; f.form.fire('change', f.form.elements.publication); await settle();
    assert.equal(f.calls.length, 11);
    assert.equal(f.node('[data-gis-filter-summary]').textContent, '2 active filters');
    f.form.fire('reset');
    Object.values(f.form.elements).forEach(input => { input.value = ''; }); f.form.elements.lifecycle.value = 'active';
    await f.flushTimers(0);
    assert.equal(f.calls.length, 18);
    assert.equal(f.node('[data-gis-filter-summary]').textContent, 'No additional filters');
    assert.equal(f.calls.at(-1).url.searchParams.get('location_type'), null);
    assert.equal(f.calls.at(-1).url.searchParams.get('lifecycle'), 'active');
});

test('parent changes clear dependent filters; opening presentation controls makes no request', async () => {
    const f = await fixture();
    f.form.elements.association_id.value = 'association-1'; f.form.elements.project_id.value = 'project-1';
    f.form.elements.municipality_id.value = 'municipality-1';
    f.form.fire('change', f.form.elements.municipality_id); await settle();
    assert.equal(f.form.elements.association_id.value, ''); assert.equal(f.form.elements.project_id.value, '');
    assert.equal(f.calls.length, 14);
    const requests = f.calls.length;
    f.node('.gis-filters').fire('toggle'); f.node('[data-gis-history]').fire('toggle'); await settle();
    assert.equal(f.calls.length, requests);
});

test('site filters reach map, extent and list without refetching parent options', async () => {
    const f = await fixture();
    const before = f.calls.length;
    f.form.elements.site_type_id.value = '2'; f.form.fire('change', f.form.elements.site_type_id); await settle();
    assert.equal(f.calls.length, before + 2);
    assert.equal(f.calls.at(-2).url.searchParams.get('site_type_id'), '2');
    assert.equal(f.calls.at(-1).url.searchParams.get('site_type_id'), '2');
    await f.fire('[data-gis-view="list"]', 'click');
    assert.equal(f.calls.at(-1).url.searchParams.get('site_type_id'), '2');
    f.form.elements.site_type_id.value = 'unclassified'; f.form.fire('change', f.form.elements.site_type_id); await settle();
    assert.equal(f.calls.at(-1).url.searchParams.get('site_type_id'), 'unclassified');
});

test('a late aborted map response cannot replace a newer selected entity', async () => {
    let release, hold = false;
    const f = await fixture({ request: (url, options, fallback) => {
        if (hold && url.searchParams.get('mode') === 'map') { hold = false; return new Promise(done => { release = done; }); }
        return fallback(url, options);
    } });
    hold = true;
    f.map.listeners.moveend(); await f.flushTimers(450);
    f.form.elements.q.value = 'new'; f.form.fire('submit'); await settle();
    f.layerCallbacks.get('site-b')();
    release({ type: 'FeatureCollection', features: [feature('stale')], meta: {} }); await settle();
    assert.equal(f.node('[data-gis-selection-title]').textContent, 'site-b');
    assert.equal(f.groups[0].layers[0].id, 'site-a');
    assert.ok(!f.layerCallbacks.has('stale'));
});

test('filter lookup failures do not partially replace options or trigger a second map reload', async () => {
    let fail = false;
    const f = await fixture({ request: (url, options, fallback) => {
        if (fail && url.searchParams.get('kind') === 'project') throw new Error('Synthetic lookup failure');
        return fallback(url, options);
    } });
    f.form.elements.association_id.value = 'association-1'; fail = true;
    await f.fire('[data-gis-reload]', 'click');
    assert.equal(f.form.elements.association_id.value, 'association-1');
    assert.equal(f.calls.length, 14);
    assert.match(f.node('[data-gis-option-note]').textContent, /could not be loaded/);
});

test('manager selection respects history/finalized actions and escapes saved content', async () => {
    for (const properties of [
        { association_archived_at: '2026-10-19', can_correct_geometry: true },
        { project_finalized: true, project_status: 'terminated' },
    ]) {
        const f = await fixture({ manager: true, request: (url, options, fallback) => url.searchParams.get('mode') === 'map'
            ? { type: 'FeatureCollection', features: [feature('site', { ...properties, title: '<img onerror=bad()>' })], meta: {} } : fallback(url, options) });
        f.layerCallbacks.get('site')();
        const actions = f.node('[data-gis-record-actions]').children;
        assert.equal(f.node('[data-gis-selection-title]').textContent, '<img onerror=bad()>');
        assert.ok(!actions.some(action => action.textContent === 'Delete permanently'));
        assert.deepEqual(actions.map(action => action.textContent), properties.can_correct_geometry
            ? ['Correct geometry'] : ['Edit location', 'Publish', 'Archive']);
    }
});

test('editor draft indicator and save label reflect unsaved and pending states', () => {
    const mark = editor.match(/^    function markDirty\(\) \{[\s\S]*?^    \}/m)[0];
    const saveState = editor.match(/^    function updateSaveState\(\) \{[\s\S]*?^    \}/m)[0];
    const context = createContext({ dirty: false, draftState: new Element(), save: new Element(), saving: false,
        associationReady: true, projectState: 'ready', saveLabel: 'Save changes' });
    runInContext(mark + '\n' + saveState, context);
    context.markDirty(); context.updateSaveState();
    assert.equal(context.dirty, true); assert.equal(context.draftState.textContent, 'Unsaved changes');
    assert.equal(context.save.disabled, false); assert.equal(context.save.textContent, 'Save changes');
    context.saving = true; context.updateSaveState();
    assert.equal(context.save.disabled, true); assert.match(context.save.textContent, /Saving/);
    context.saving = false; context.projectState = 'error'; context.updateSaveState();
    assert.equal(context.save.disabled, true); assert.equal(context.save.textContent, 'Save changes');
});
