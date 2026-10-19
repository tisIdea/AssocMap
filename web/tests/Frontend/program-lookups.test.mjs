import test from 'node:test';
import assert from 'node:assert/strict';
import { applyLookupResults, initProgramLookups, loadLookup, loadTrainingChoices } from '../../resources/js/pages/program-lookups.js';

function emitter(extra = {}) {
    const handlers = new Map();
    return Object.assign(extra, {
        handlers,
        addEventListener(type, handler) { handlers.set(type, [...(handlers.get(type) ?? []), handler]); },
        fire(type, event = {}) { for (const handler of handlers.get(type) ?? []) handler(event); },
    });
}

class FakeOption {
    constructor(label, value) { this.textContent = label; this.value = value; }
    cloneNode() { return new FakeOption(this.textContent, this.value); }
}

function select(value = '') {
    const record = {
        tagName: 'SELECT', value, options: value ? [new FakeOption('Retained selection', value)] : [],
        get selectedOptions() { return this.options.filter(option => option.value === this.value); },
        replaceChildren(...options) { this.options = options; this.value = options[0]?.value ?? ''; },
        add(option) { this.options.push(option); },
    };
    return record;
}

function fixture({ training = false } = {}) {
    globalThis.Option = FakeOption;
    globalThis.CustomEvent = class { constructor(type, properties) { this.type = type; Object.assign(this, properties); } };
    globalThis.window = { location: { origin: 'https://assocmap.example.test' } };
    const form = emitter({ dataset: {}, elements: { association_id: emitter(select('association-a')), related_project_id: select('project-old') }, events: [],
        dispatchEvent(event) { this.events.push(event); },
        querySelectorAll() { return pickers; },
    });
    const createPicker = (kind, target) => {
        const controls = {
            '[data-lookup-term]': emitter({ value: '' }),
            '[data-lookup-search]': emitter({ disabled: false }),
            '[data-lookup-previous]': emitter({ hidden: true, disabled: false }),
            '[data-lookup-next]': emitter({ hidden: true, disabled: false }),
            '[data-lookup-status]': { textContent: '' },
        };
        return {
            dataset: { programLookup: kind, lookupTarget: target, lookupUrl: 'https://assocmap.example.test/admin/trainings' },
            controls, attributes: {},
            closest: () => form,
            querySelector: selector => controls[selector],
            querySelectorAll: () => Object.values(controls).filter(control => 'disabled' in control),
            setAttribute(name, value) { this.attributes[name] = value; },
            removeAttribute(name) { delete this.attributes[name]; },
        };
    };
    const pickers = [createPicker('associations', 'association_id')];
    if (training) {
        form.elements.training_record_id = { value: 'training-a' };
        pickers.push(createPicker('projects', 'related_project_id'), createPicker('members', 'participant_choices'));
    }
    const pageLink = emitter({});
    const root = { querySelectorAll: selector => selector === '[data-program-lookup]' ? pickers : [pageLink] };
    const document = { events: [], dispatchEvent(event) { this.events.push(event); } };
    globalThis.document = document;
    return { form, pickers, pageLink, root, document };
}

const response = body => ({ ok: true, headers: { get: () => 'application/json' }, json: async () => body });
const result = (value = 'association-b', extra = {}) => ({ options: [{ value, label: 'Matching choice', association_id: 'association-a' }], page: 1, has_more: false, ...extra });

test('paged choices preserve the selected record and render labels as text', () => {
    const { form, pickers } = fixture();
    applyLookupResults(pickers[0], result('association-b', { page: 2, has_more: true }));
    assert.equal(form.elements.association_id.value, 'association-a');
    assert.deepEqual(form.elements.association_id.options.map(option => option.value), ['', 'association-b', 'association-a']);
    assert.equal(pickers[0].controls['[data-lookup-previous]'].hidden, false);
    assert.equal(pickers[0].controls['[data-lookup-next]'].hidden, false);
    assert.equal(form.events[0].detail.kind, 'associations');
});

test('manual lookups use one scoped GET with encoded search and expose a loading state', async () => {
    const { pickers } = fixture({ training: true });
    const picker = pickers[2];
    picker.controls['[data-lookup-term]'].value = ' A & B ';
    let release;
    const calls = [];
    globalThis.fetch = async (url, options) => { calls.push({ url, options }); return new Promise(resolve => { release = resolve; }); };
    const loading = loadLookup(picker, 2);
    assert.equal(picker.attributes['aria-busy'], 'true');
    assert.equal(picker.controls['[data-lookup-search]'].disabled, true);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url.searchParams.get('association_id'), 'association-a');
    assert.equal(calls[0].url.searchParams.get('term'), 'A & B');
    assert.equal(calls[0].url.searchParams.get('lookup_page'), '2');
    assert.equal(calls[0].url.searchParams.get('training_id'), 'training-a');
    assert.equal(calls[0].options.credentials, 'same-origin');
    release(response(result('member-b', { page: 2 })));
    await loading;
    assert.equal(picker.attributes['aria-busy'], undefined);
    assert.equal(picker.controls['[data-lookup-search]'].disabled, false);
});

test('a failed or invalid response keeps current selections and allows retry', async () => {
    const { form, pickers } = fixture();
    for (const value of [{ ok: false }, response({ options: null }), response(result(123))]) {
        globalThis.fetch = async () => value;
        await loadLookup(pickers[0]);
        assert.equal(form.elements.association_id.value, 'association-a');
        assert.match(pickers[0].controls['[data-lookup-status]'].textContent, /unavailable/);
        assert.equal(pickers[0].controls['[data-lookup-search]'].disabled, false);
    }
});

test('a late response cannot overwrite a newer search', async () => {
    const { pickers } = fixture();
    const releases = [];
    globalThis.fetch = async () => new Promise(resolve => releases.push(resolve));
    const first = loadLookup(pickers[0]);
    const second = loadLookup(pickers[0]);
    releases[1](response(result('new-result')));
    await second;
    releases[0](response(result('old-result')));
    await first;
    assert.equal(pickers[0].closest().elements.association_id.options.some(option => option.value === 'new-result'), true);
    assert.equal(pickers[0].closest().elements.association_id.options.some(option => option.value === 'old-result'), false);
});

test('an association change batches project and member choices in one request', async () => {
    const { form, pickers } = fixture({ training: true });
    const calls = [];
    globalThis.fetch = async url => { calls.push(url); return response({ projects: result('project-a'), members: result('member-a') }); };
    await loadTrainingChoices(form);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].searchParams.get('lookup'), 'training_choices');
    assert.equal(calls[0].searchParams.get('association_id'), 'association-a');
    assert.equal(calls[0].searchParams.get('training_id'), 'training-a');
    assert.deepEqual(form.events.map(event => event.detail.kind), ['projects', 'members']);
    assert.equal(pickers[1].attributes['aria-busy'], undefined);
    assert.equal(pickers[2].attributes['aria-busy'], undefined);
});

test('a lookup without an association avoids requests and explains the required choice', async () => {
    const { form, pickers } = fixture({ training: true });
    form.elements.association_id.value = '';
    globalThis.fetch = () => { throw new Error('unexpected request'); };
    await loadLookup(pickers[2]);
    assert.equal(pickers[2].controls['[data-lookup-status]'].textContent, 'Select an association first.');
    await loadTrainingChoices(form);
    assert.equal(form.events.length, 2);
    assert.deepEqual(form.events[0].detail.options, []);
});

test('initialization is idempotent and participant paging confirms unsaved changes', () => {
    const { form, pickers, pageLink, root, document } = fixture({ training: true });
    initProgramLookups(root);
    initProgramLookups(root);
    assert.equal(pickers[0].controls['[data-lookup-search]'].handlers.get('click').length, 1);
    assert.equal(form.elements.association_id.handlers.get('change').length, 1);
    assert.equal(pageLink.handlers.get('click').length, 1);
    let prevented = false;
    form.fire('input');
    pageLink.fire('click', { preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(document.events[0].type, 'assocmap:confirm-navigation');
    assert.match(document.events[0].detail.message, /Save Training first/);
    prevented = false;
    pageLink.fire('click', { ctrlKey: true, preventDefault() { prevented = true; } });
    assert.equal(prevented, false);
});

test('SAAD activity lookups work without an association and keep choice IDs as strings', async () => {
    const { form, pickers } = fixture();
    form.elements.association_id.value = '';
    form.elements.saad_activity_id = select('3');
    pickers[0].dataset.programLookup = 'activities';
    pickers[0].dataset.lookupTarget = 'saad_activity_id';
    const calls = [];
    globalThis.fetch = async url => { calls.push(url); return response(result('40')); };
    await loadLookup(pickers[0]);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].searchParams.get('lookup'), 'activities');
    assert.equal(calls[0].searchParams.has('association_id'), false);
    assert.equal(form.elements.saad_activity_id.value, '3');
});

test('searching associations preserves the Program-wide empty choice label', () => {
    const { form, pickers } = fixture();
    pickers[0].dataset.lookupEmptyLabel = 'Program-wide (no association)';
    applyLookupResults(pickers[0], result());
    assert.equal(form.elements.association_id.options[0].textContent, 'Program-wide (no association)');
});

test('Activity initialization is lazy and an explicit owner change reloads only Project choices', async () => {
    const { form, pickers, root } = fixture({ training: true });
    pickers.pop();
    delete form.elements.training_record_id;
    form.dataset.programActivityForm = '';
    const notice = { hidden: true, textContent: '' };
    form.querySelector = selector => selector === '[data-activity-project-notice]' ? notice : pickers[1];
    const calls = [];
    globalThis.fetch = async url => { calls.push(url); return response(result('project-new')); };
    initProgramLookups(root);
    initProgramLookups(root);
    assert.equal(calls.length, 0);
    form.elements.association_id.value = 'association-new';
    form.elements.association_id.fire('change');
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(calls.length, 1);
    assert.equal(calls[0].searchParams.get('lookup'), 'projects');
    assert.equal(calls[0].searchParams.get('association_id'), 'association-new');
    assert.equal(form.elements.related_project_id.value, '');
    assert.equal(form.elements.related_project_id.options.some(option => option.value === 'project-old'), false);
    assert.equal(notice.hidden, false);
    assert.match(notice.textContent, /selection was cleared/);
});

test('clearing an Activity owner cancels pending choices so a late response cannot restore a removed link', async () => {
    const { form, pickers, root } = fixture({ training: true });
    pickers.pop();
    form.dataset.programActivityForm = '';
    form.querySelector = selector => selector === '[data-activity-project-notice]' ? null : pickers[1];
    let release;
    globalThis.fetch = () => new Promise(resolve => { release = resolve; });
    initProgramLookups(root);
    form.elements.association_id.fire('change');
    form.elements.association_id.value = '';
    form.elements.association_id.fire('change');
    release(response(result('stale-project')));
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(form.elements.related_project_id.options.map(option => option.value), ['']);
    assert.equal(pickers[1].attributes['aria-busy'], undefined);
    assert.equal(pickers[1].controls['[data-lookup-search]'].disabled, false);
});
