import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/components/contextual-report.js', import.meta.url), 'utf8')
    .replace(/^import .*;\r?\n/gm, '').replace(/^export /gm, '');

test('contextual actions initialize every existing form once and never generate reports on load', () => {
    const handlers = [], initialized = [];
    const summary = { focused: false, focus() { this.focused = true; } };
    const menu = { open: true, addEventListener: (_name, fn) => handlers.push(fn), querySelector: () => summary };
    const forms = [{}, {}];
    const roots = [...forms, null].map(form => ({ dataset: {}, querySelector: selector => selector === 'details' ? (form ? menu : null) : form }));
    const sandbox = {
        document: { querySelectorAll: () => roots },
        initReportDownloads: form => { if (form) initialized.push(form); },
        fetch: () => assert.fail('Contextual initialization must not issue any report requests.'),
    };
    runInNewContext(source, sandbox);
    sandbox.initContextualReports();
    sandbox.initContextualReports();
    assert.deepEqual(initialized, forms);
    assert.equal(handlers.length, 2);
    let prevented = false;
    handlers[0]({ key: 'Tab', preventDefault: () => { prevented = true; } });
    assert.equal(prevented, false);
    assert.equal(menu.open, true);
    handlers[0]({ key: 'Escape', preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(menu.open, false);
    assert.equal(summary.focused, true);
});
