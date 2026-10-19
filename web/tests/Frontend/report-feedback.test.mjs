import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const downloads = readFileSync(new URL('../../resources/js/pages/report-download.js', import.meta.url), 'utf8').replace(/^import .*\r?\n/gm, '').replace(/^export /gm, '');
const reports = readFileSync(new URL('../../resources/js/pages/admin/reports.js', import.meta.url), 'utf8').replace(/\r\n/g, '\n');
const jsonHelper = reports.match(/^    async function json\(url, signal\) \{[\s\S]*?^    \}/m)[0];
const classification = readFileSync(new URL('../../resources/js/core/feedback.js', import.meta.url), 'utf8').replace(/^export /gm, '');

function response(status = 200, type = 'application/pdf', body = {}, filename = 'assocmap-monitoring-20261017-120000.pdf') {
    return { ok: status >= 200 && status < 300, status,
        headers: { get: name => ({ 'content-type': type, 'content-disposition': `attachment; filename="${filename}"` })[name.toLowerCase()] ?? null },
        json: async () => { if (body === 'malformed') throw new SyntaxError(); return body; }, blob: async () => new Blob(['synthetic artifact']) };
}

function fixture(reply = response(), { format = 'pdf', clickThrows = false } = {}) {
    const success = [], errors = [], fields = [], requests = [], anchors = [], revoked = [], timers = [], listeners = [];
    const button = { formAction: `https://assocmap.test/admin/reports/export/${format}`, disabled: false }, second = { disabled: false };
    const filterForm = {};
    const form = { dataset: { exportReady: 'true' }, attributes: new Map(),
        addEventListener: (name, fn) => listeners.push(fn), querySelectorAll: () => [button, second],
        closest: () => ({ querySelector: () => filterForm }),
        setAttribute(name, value) { this.attributes.set(name, value); }, removeAttribute(name) { this.attributes.delete(name); } };
    class DownloadURL extends URL {
        static createObjectURL() { return 'blob:synthetic'; }
        static revokeObjectURL(url) { revoked.push(url); }
    }
    const context = { URL: DownloadURL, FormData: class { constructor(value) { this.form = value; this.csrf = 'synthetic-token'; } },
        window: { location: { href: 'https://assocmap.test/admin/reports', origin: 'https://assocmap.test' } },
        document: { body: { append: node => anchors.push(node) }, createElement: () => ({
            click() { this.clicks = (this.clicks ?? 0) + 1; if (clickThrows) throw new Error('Download blocked'); }, remove() { this.removed = true; } }) },
        fetch: async (url, options) => { requests.push({ url, options }); return typeof reply === 'function' ? reply() : reply; },
        applyFormErrors: (target, errors) => fields.push({ target, errors }),
        showFeedback: result => success.push(result), showOperationError: error => errors.push(error), setTimeout: fn => timers.push(fn) };
    runInNewContext(downloads, context); context.initReportDownloads(form); context.initReportDownloads(form);
    return { form, button, second, listeners, success, errors, fields, requests, anchors, revoked, filterForm,
        submit: () => listeners[0]({ submitter: button, preventDefault() {} }), flush: () => timers.splice(0).forEach(fn => fn()) };
}

for (const [format, mime] of [['pdf', 'application/pdf'], ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']]) {
    test(`${format} export preserves POST/session/CSRF, server filename and one download; URL is released`, async () => {
        const filename = `assocmap-monitoring-20261017-120000.${format}`;
        const f = fixture(response(200, mime, {}, filename), { format }); await f.submit();
        assert.equal(f.listeners.length, 1); assert.equal(f.requests.length, 1); assert.equal(f.anchors.length, 1);
        assert.equal(f.requests[0].options.method, 'POST'); assert.equal(f.requests[0].options.credentials, 'same-origin');
        assert.equal(f.requests[0].options.redirect, 'error'); assert.equal(f.requests[0].options.body.form, f.form);
        assert.equal(f.anchors[0].download, filename); assert.equal(f.anchors[0].removed, true); assert.equal(f.anchors[0].clicks, 1);
        assert.equal(f.success.length, 1); assert.match(f.success[0].message, /download was requested/); assert.doesNotMatch(f.success[0].message, /saved/);
        assert.equal(f.button.disabled, false); assert.equal(f.form.attributes.has('aria-busy'), false);
        f.flush(); assert.deepEqual(f.revoked, ['blob:synthetic']);
    });
}

test('pending export suppresses duplicate submission and respects filters becoming stale', async () => {
    let resolve;
    const f = fixture(() => new Promise(done => { resolve = done; }));
    const pending = f.submit(); await f.submit(); assert.equal(f.requests.length, 1); assert.equal(f.button.disabled, true);
    f.form.dataset.exportReady = 'false'; resolve(response()); await pending;
    assert.equal(f.success.length, 1); assert.equal(f.button.disabled, true); assert.equal(f.second.disabled, true);
});

test('HTTP failures never download; malformed JSON retains the original status and field errors stay scoped', async () => {
    for (const status of [403, 409, 419, 422, 429, 500, 503]) {
        for (const body of [{ errors: { year: ['Correct the year.'] }, message: 'PRIVATE INTERNAL DETAILS' }, 'malformed']) {
            const f = fixture(response(status, 'application/json', body)); await f.submit();
            assert.equal(f.errors[0].status, status); assert.equal(f.anchors.length, 0); assert.equal(f.success.length, 0);
            if (status === 422) assert.equal(f.fields[0].target, f.filterForm);
            assert.equal(f.button.disabled, false);
        }
    }
});

test('HTML, wrong MIME, empty binary and network responses cannot become successful downloads', async () => {
    const empty = response(); empty.blob = async () => new Blob([]);
    for (const reply of [response(200, 'text/html'), response(200, 'application/pdf-malformed'), empty,
        () => { throw new TypeError('Network unavailable'); }]) {
        const f = fixture(reply); await f.submit(); assert.equal(f.success.length, 0); assert.equal(f.errors.length, 1);
        assert.equal(f.anchors.length, 0); assert.equal(f.form.dataset.downloadPending, undefined);
    }
});

test('untrusted filenames fall back safely and object URLs are released even when initiation fails', async () => {
    const f = fixture(response(200, 'application/pdf', {}, '../../private.html')); await f.submit();
    assert.equal(f.anchors[0].download, 'assocmap-report.pdf');
    const blocked = fixture(response(), { clickThrows: true }); await blocked.submit(); blocked.flush();
    assert.equal(blocked.success.length, 0); assert.equal(blocked.errors.length, 1);
    assert.equal(blocked.anchors[0].removed, true); assert.deepEqual(blocked.revoked, ['blob:synthetic']);
    f.button.formAction = 'https://foreign.test/export/pdf'; await f.submit(); assert.equal(f.requests.length, 1);
});

test('actual report JSON helper preserves status for HTML/malformed failures and never exposes internal response messages', async () => {
    const requests = [], context = { fetch: async (url, options) => { requests.push(options); return context.reply; } };
    runInNewContext(classification + '\n' + jsonHelper, context);
    for (const status of [403, 422, 429, 500, 503]) {
        for (const type of ['application/json', 'text/html']) {
            context.reply = response(status, type, { message: 'PRIVATE PYTHON TRACE', errors: { year: ['Correct year.'] } });
            await assert.rejects(context.json('/report'), error => error.status === status && !error.message.includes('PRIVATE'));
            context.reply = response(status, type, 'malformed');
            await assert.rejects(context.json('/report'), error => error.status === status);
        }
    }
    context.reply = response(200, 'text/html'); await assert.rejects(context.json('/report'), error => error.status === 503);
    context.reply = response(200, 'application/json', { data: { rows: [] } });
    assert.deepEqual(await context.json('/report'), { rows: [] });
    assert.equal(requests[0].credentials, 'same-origin'); assert.equal(requests[0].redirect, 'error');
});

test('explicit preview submission emits one result, suppresses pending duplicates and ignores stale responses', async () => {
    const submit = reports.match(/^    form.addEventListener\('submit', async event => \{[\s\S]*?^    \}\);/m)[0];
    for (const outcome of ['success', 'error', 'stale', 'abort']) {
        const success = [], errors = [], rendered = [], requests = [], button = {};
        let handler, complete;
        const form = { action: '/reports', reportValidity: () => true, querySelector: () => button,
            addEventListener: (name, fn) => { handler = fn; } };
        const context = { form, request: undefined, optionsRequest: undefined, revision: 0, relevance() {}, filters: () => new URLSearchParams('year=2026'),
            AbortController, root: { dataset: { dataUrl: '/reports/data' } }, exportsEnabled() {}, preview: { replaceChildren() {} }, syncBusy() {},
            status: { dataset: {}, getAttribute: () => 'status' }, showStatus() {}, history: { replaceState() {} }, applyFormErrors() {},
            json: async () => { requests.push(true); return new Promise((resolve, reject) => { complete = () => {
                if (outcome === 'error') { const error = new Error('Safe failure'); error.status = 503; reject(error); }
                else if (outcome === 'abort') { const error = new Error('Aborted'); error.name = 'AbortError'; reject(error); }
                else resolve({ empty: true });
            }; }); }, render: result => rendered.push(result), showFeedback: result => success.push(result), showOperationError: error => errors.push(error) };
        runInNewContext(submit, context);
        const first = handler({ preventDefault() {} }); await handler({ preventDefault() {} }); assert.equal(requests.length, 1);
        if (outcome === 'stale') context.revision++;
        complete(); await first;
        assert.equal(success.length, outcome === 'success' ? 1 : 0); assert.equal(errors.length, outcome === 'error' ? 1 : 0);
        assert.equal(rendered.length, outcome === 'success' ? 1 : 0); assert.equal(context.request, undefined);
    }
});

test('reinitializing the reporting workspace does not bind another handler', () => {
    const context = { document: { querySelector: () => ({ dataset: { reportsInitialized: 'true' }, querySelector() { assert.fail('Duplicate initialization'); } }) } };
    runInNewContext(reports.replace(/^import .*\n/gm, '').replace(/^export /gm, ''), context);
    context.initReports(); context.initReports();
});
