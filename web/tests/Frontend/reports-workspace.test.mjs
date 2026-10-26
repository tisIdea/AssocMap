import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/pages/admin/reports.js', import.meta.url), 'utf8')
    .replace(/^import .*\r?\n/gm, '').replace(/^export /gm, '')
    .replace("import('../../components/report-analytics.js')", 'loadReportAnalytics()');

class Node {
    constructor(tag = 'div', text = '') {
        this.tagName = tag;
        this.textContent = text;
        this.children = [];
        this.dataset = {};
        this.attributes = new Map();
        this.listeners = new Map();
        this.style = {};
        this.className = '';
        this.classList = { toggle() {} };
    }
    append(...nodes) { this.children.push(...nodes); }
    replaceChildren(...nodes) { this.children = nodes; }
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    getAttribute(name) { return this.attributes.get(name); }
    removeAttribute(name) { this.attributes.delete(name); }
    contains(node) { return this === node || this.children.some(child => child.contains?.(node)); }
    addEventListener(name, callback) {
        this.listeners.set(name, [...(this.listeners.get(name) ?? []), callback]);
    }
    async fire(name, event = {}) {
        for (const callback of this.listeners.get(name) ?? []) await callback({ preventDefault() {}, ...event });
    }
    focus() { this.focused = true; }
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
    querySelectorAll(selector) {
        const matches = node => selector.startsWith('.') ? node.className.split(' ').includes(selector.slice(1)) : node.tagName === selector;
        return this.children.flatMap(child => [ ...(matches(child) ? [child] : []), ...child.querySelectorAll(selector)]);
    }
}

function fixture({ account = false, result = undefined, script = source, analyticsLoader = null } = {}) {
    const year = 2026;
    const filters = { report_type: 'production', year };
    const map = { production: ['municipality_id', 'association_id', 'year', 'quarter', 'month', 'project_id', 'product_id', 'program_year', 'component_id'],
        associations: ['municipality_id', 'association_id'] };
    const options = (values = []) => values.map(value => ({ value: String(value), textContent: String(value), dataset: {} }));
    const fields = Object.fromEntries(Object.entries({ report_type: 'production', year: String(year), quarter: '', month: '',
        project_id: '', product_id: '', program_year: '', component_id: '', ...(account ? {} : { municipality_id: '', association_id: '' }) })
        .map(([name, value]) => [name, { name, value, options: options(['', value, year, 2024]), disabled: false }]));
    fields.report_type.options = options(['production', 'associations']);
    for (const field of Object.values(fields)) {
        field.replaceChildren = () => { field.options = []; field.value = ''; };
        field.append = option => field.options.push(option);
        Object.defineProperty(field, 'selectedOptions', { get: () => field.options.filter(option => option.value === field.value) });
    }
    const form = new Node('form');
    form.elements = { namedItem: name => fields[name] };
    form.action = 'https://assocmap.test/admin/reports';
    form.reportValidity = () => true;
    const submit = new Node('button');
    form.querySelector = () => submit;
    const preview = new Node();
    const status = new Node('p');
    const toggle = new Node('button');
    const panel = new Node('section');
    const count = new Node('span');
    const indicator = new Node('span');
    const menu = account ? null : new Node('details');
    menu?.append(new Node('summary'));
    const exportForm = account ? null : new Node('form');
    const exportFilters = new Node('div');
    const exportButtons = [new Node('button'), new Node('button')];
    if (exportForm) {
        exportForm.append(...exportButtons);
        exportForm.querySelector = selector => selector === '[data-export-filters]' ? exportFilters : null;
    }
    const section = { title: 'Recorded observations', measures: [{ label: 'Recorded zero', value: 0 }, { label: 'Unobserved', value: null }], notes: [],
        columns: [{ key: 'label', label: 'Product', type: 'text' }, { key: 'count', label: 'Count', type: 'integer' }, { key: 'amount', label: 'Exact amount', type: 'decimal' }],
        rows: Array.from({ length: 102 }, (_, index) => ({ label: index === 0 ? '<script>unsafe</script>' : `Row ${index + 1}`, count: index, amount: index === 1 ? null : '1234567890.12' })),
        chart: { title: 'Observation counts', label_key: 'label', value_key: 'count', unit: 'observations' } };
    const initialResult = result === undefined ? { title: 'Production Performance', empty: false, notes: ['Recorded data only'], sections: [section],
        meta: { filters, generated_at: '2026-10-19T10:00:00+08:00', timezone: 'Asia/Manila', scope: ['Synthetic association'], scope_basis: 'Synthetic scope' } } : result;
    const initial = { result: initialResult, filterMap: map, defaultYear: year };
    const nodes = { '[data-report-form]': form, '[data-report-result]': preview, '[data-report-status]': status, '[data-report-export]': exportForm,
        '[data-report-initial]': { textContent: JSON.stringify(initial) }, '[data-report-filter-toggle]': toggle,
        '#report-filter-panel': panel, '[data-report-filter-count]': count, '[data-report-filter-status]': indicator, '[data-report-export-menu]': menu };
    const root = new Node();
    root.dataset = { dataUrl: '/admin/reports/data', optionsUrl: '/admin/reports/options' };
    root.querySelector = selector => nodes[selector] ?? null;
    const containers = ['year', 'quarter', 'month', 'project_id', 'product_id', 'program_year', 'component_id'].map(name => ({
        dataset: { reportFilter: name }, querySelector: () => fields[name] }));
    root.querySelectorAll = () => containers;
    const requests = [], history = [], errors = [], feedback = [];
    const context = { document: { querySelector: () => root, createElement: tag => new Node(tag) },
        loadReportAnalytics: analyticsLoader || (async () => ({ renderReportAnalytics: (_result, target) => { target.removeAttribute('aria-busy'); target.append(new Node('p', 'Synthetic chart renderer')); } })),
        FormData: class { constructor() { return Object.values(fields).filter(field => !field.disabled).map(field => [field.name, field.value]); } },
        Option: class { constructor(name, id) { this.textContent = name; this.value = id; } },
        URLSearchParams, AbortController,
        initReportDownloads() {}, applyFormErrors: (form, fields) => errors.push(fields),
        errorFeedback: status => ({ message: `Safe report error ${status}` }), showFeedback: message => feedback.push(message), showOperationError() {},
        history: { replaceState: (...args) => history.push(args[2]) }, fetch: async (url, settings) => {
            requests.push({ url, settings });
            return context.reply(url);
        }, reply: () => ({ ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ data: initialResult }) }) };
    runInNewContext(script, context);
    context.initReports();
    return { root, form, fields, preview, toggle, panel, count, indicator, menu, status, requests, history, exportForm, exportFilters, exportButtons, initialResult, context, errors, feedback,
        submit: () => form.fire('submit'), change: name => form.fire('change', { target: fields[name] }) };
}

test('filter disclosure and export Escape preserve focus and issue no requests', async () => {
    const f = fixture();
    assert.equal(f.requests.length, 0);
    assert.equal(f.panel.hidden, true);
    assert.equal(f.toggle.hidden, false);
    assert.equal(f.toggle.getAttribute('aria-expanded'), 'false');
    await f.toggle.fire('click');
    assert.equal(f.panel.hidden, false);
    assert.equal(f.toggle.getAttribute('aria-expanded'), 'true');
    await f.panel.fire('keydown', { key: 'Escape' });
    assert.equal(f.panel.hidden, true);
    assert.equal(f.toggle.focused, true);
    f.menu.open = true;
    await f.menu.fire('keydown', { key: 'Escape' });
    assert.equal(f.menu.open, false);
    assert.equal(f.menu.querySelector('summary').focused, true);
    assert.equal(f.requests.length, 0);
    await f.form.fire('invalid');
    assert.equal(f.panel.hidden, false);
});

test('active filters exclude the report type and default year, invalidate exports, and retain the applied query', async () => {
    const f = fixture();
    assert.equal(f.count.hidden, true);
    f.fields.year.value = '2024'; f.fields.quarter.value = '2';
    await f.change('quarter');
    assert.equal(f.count.textContent, '2');
    assert.equal(f.count.hidden, false);
    assert.equal(f.toggle.getAttribute('aria-label'), 'Filters, 2 active');
    assert.equal(f.preview.children.length, 0);
    assert.equal(f.exportForm.dataset.exportReady, 'false');
    assert.ok(f.exportButtons.every(button => button.disabled));
    assert.equal(f.requests.length, 0);
    const next = structuredClone(f.initialResult);
    next.meta.filters = { report_type: 'production', year: 2024, quarter: 2 };
    f.context.reply = () => ({ ok: true, headers: { get: () => 'application/json' }, json: async () => ({ data: next }) });
    await f.submit();
    assert.equal(f.requests.length, 1);
    assert.match(f.requests[0].url, /year=2024&quarter=2/);
    assert.match(f.history[0], /year=2024&quarter=2/);
    assert.deepEqual(Object.fromEntries(f.exportFilters.children.map(node => [node.name, String(node.value)])),
        { report_type: 'production', year: '2024', quarter: '2' });
    assert.equal(f.exportForm.dataset.exportReady, 'true');
});

test('all bounded table pages preserve exact values, nulls and text after analytics are separated', async () => {
    const f = fixture();
    const section = f.preview.querySelector('.report-section');
    const table = section.querySelector('table');
    const body = table.querySelector('tbody');
    assert.equal(body.children.length, 50);
    assert.equal(body.children[0].children[0].textContent, '<script>unsafe</script>');
    assert.equal(body.children[0].children[1].textContent, 0);
    assert.equal(body.children[0].children[2].textContent, '1234567890.12');
    assert.equal(body.children[1].children[2].textContent, 'Unobserved / N/A');
    assert.equal(body.children[0].children[2].className, 'report-numeric');
    assert.equal(section.querySelector('.report-bars'), null);
    assert.equal(f.preview.querySelector('.report-summary').querySelectorAll('dd').length, 2);
    assert.ok(f.preview.querySelector('.report-analytics'));
    const pages = section.querySelector('.report-pagination');
    const [previous, context, next] = pages.children;
    assert.equal(pages.getAttribute('aria-label'), 'Recorded observations table pages');
    await next.fire('click');
    assert.equal(body.children[0].children[0].textContent, 'Row 51');
    await next.fire('click');
    assert.equal(body.children.length, 2);
    assert.match(context.textContent, /101.*102 of 102/);
    assert.equal(next.disabled, true);
    await previous.fire('click');
    assert.equal(body.children.length, 50);
    assert.equal(f.requests.length, 0);
});

test('changing report type clears irrelevant periods and requests options once without running analytics', async () => {
    const f = fixture();
    f.fields.quarter.value = '2'; f.fields.month.value = '4';
    f.fields.report_type.value = 'associations';
    f.context.reply = () => ({ ok: true, headers: { get: () => 'application/json' }, json: async () => ({ data: {
        projects: [], products: [], components: [], program_years: [], years: [2026] } }) });
    await f.change('report_type');
    await new Promise(setImmediate);
    assert.equal(f.fields.quarter.disabled, true);
    assert.equal(f.fields.quarter.value, '');
    assert.equal(f.fields.month.value, '');
    assert.equal(f.count.hidden, true);
    assert.equal(f.requests.length, 1);
    assert.match(f.requests[0].url, /options\?report_type=associations$/);
    assert.equal(f.exportForm.dataset.exportReady, 'false');
});

test('association account initializes the same presentation without geographic or export controls', async () => {
    const f = fixture({ account: true });
    await f.toggle.fire('click');
    assert.equal(f.panel.hidden, false);
    assert.equal(f.preview.querySelectorAll('table').length, 1);
    assert.equal(f.exportForm, null);
    assert.equal(f.requests.length, 0);
});

test('missing results remain empty with exports unavailable', () => {
    const f = fixture({ result: null });
    assert.equal(f.preview.children.length, 0);
    assert.equal(f.requests.length, 0);
});

test('an analytics import completing after a filter change cannot display stale observations', async () => {
    let complete, rendered = 0;
    const waiting = new Promise(resolve => { complete = resolve; });
    const f = fixture({ analyticsLoader: () => waiting });
    f.fields.quarter.value = '2';
    await f.change('quarter');
    complete({ renderReportAnalytics: () => { rendered++; } });
    await new Promise(setImmediate);
    assert.equal(rendered, 0);
    assert.equal(f.preview.children.length, 0);
    assert.equal(f.exportForm.dataset.exportReady, 'false');
});

test('a chart loading failure retains exact tables, summaries and existing exports', async () => {
    const f = fixture({ analyticsLoader: async () => { throw new Error('Synthetic chunk unavailable'); } });
    await new Promise(setImmediate);
    assert.equal(f.preview.querySelectorAll('table').length, 1);
    assert.equal(f.preview.querySelector('.report-summary').querySelectorAll('dd').length, 2);
    assert.equal(f.preview.querySelector('.report-analytics').getAttribute('aria-busy'), undefined);
    assert.equal(f.exportForm.dataset.exportReady, 'true');
    assert.equal(f.requests.length, 0);
});

test('an unavailable report never loads chart code or requests extra datasets', () => {
    let loaded = 0;
    const f = fixture({ result: null, analyticsLoader: () => { loaded++; } });
    assert.equal(loaded, 0);
    assert.equal(f.requests.length, 0);
});
