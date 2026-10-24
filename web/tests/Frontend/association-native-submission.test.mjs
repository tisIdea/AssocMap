import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, join, resolve, sep } from 'node:path';
import { pathToFileURL } from 'node:url';
import { before, test } from 'node:test';

const fixture = process.env.ASSOCMAP_ASSOCIATION_EDIT_HTML;
const resultFile = process.env.ASSOCMAP_ASSOCIATION_SUBMISSION_RESULT;
const scenarios = ['clear-before-address', 'repeat-parent-updates', 'parent-round-trip'];
let observations;

before(() => {
    if (!fixture) return;
    const browser = process.env.ASSOCMAP_TEST_BROWSER ?? [
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
    ].find(existsSync);
    assert.ok(browser && existsSync(browser), 'Set ASSOCMAP_TEST_BROWSER to an installed Chromium browser.');
    const directory = mkdtempSync(join(tmpdir(), 'assocmap-native-'));
    try {
        const helpers = readFileSync(new URL('../../resources/js/core/helpers.js', import.meta.url), 'utf8').replace(/^export /gm, '');
        const forms = readFileSync(new URL('../../resources/js/pages/admin/forms.js', import.meta.url), 'utf8')
            .replace(/^import .*;\s*/m, '').replace(/^export /gm, '');
        const markup = JSON.stringify(readFileSync(fixture, 'utf8')).replaceAll('<', '\\u003c');
        const script = `
${helpers}
${forms}
const markup = ${markup};
const results = [];
const errors = [];
window.addEventListener('error', event => errors.push(event.message));
const change = input => input.dispatchEvent(new Event('change', { bubbles: true }));
for (const scenario of ${JSON.stringify(scenarios)}) {
    document.getElementById('fixture').innerHTML = markup;
    const form = document.querySelector('[data-association-form]');
    const area = form.elements.namedItem('area_id');
    const address = form.elements.namedItem('specific_address');
    const municipality = form.elements.namedItem('municipality_id');
    const barangay = form.elements.namedItem('barangay_id');
    const initial = { area: area.value, address: address.value, municipality: municipality.value, barangay: barangay.value };
    initForms();
    form.closest('dialog').showModal();
    area.focus();
    area.value = '';
    area.dispatchEvent(new Event('input', { bubbles: true }));
    change(area);
    address.focus();
    address.value = 'S4-PERSISTENCE-TEST';
    address.dispatchEvent(new Event('input', { bubbles: true }));
    change(address);
    address.blur();
    if (scenario === 'repeat-parent-updates') {
        change(municipality);
        change(barangay);
        address.focus(); address.blur();
    }
    if (scenario === 'parent-round-trip') {
        const alternative = JSON.parse(form.dataset.barangayRecords).find(record => record.municipality_id !== initial.municipality);
        if (!alternative) throw new Error('The isolated fixture requires a second eligible Municipality/Barangay.');
        municipality.value = alternative.municipality_id; change(municipality);
        barangay.value = alternative.value; change(barangay);
        municipality.value = initial.municipality; change(municipality);
        barangay.value = initial.barangay; change(barangay);
    }
    const beforeSubmit = {
        area: area.value, areaLabel: area.selectedOptions[0]?.textContent.trim(),
        areaControls: form.querySelectorAll('[name="area_id"]').length,
        municipality: municipality.value, barangay: barangay.value, address: address.value,
    };
    let submitted = null;
    form.addEventListener('submit', event => {
        event.preventDefault();
        const data = new FormData(form);
        submitted = { entries: [...data.entries()], areaValues: data.getAll('area_id'),
            urlEncoded: new URLSearchParams(data).toString(), method: form.method };
    });
    form.requestSubmit();
    results.push({ scenario, initial, beforeSubmit, submitted });
    form.closest('dialog').close();
}
document.getElementById('native-result').textContent = btoa(unescape(encodeURIComponent(JSON.stringify({ results, errors }))));
`;
        const page = join(directory, 'submission.html');
        // No remote resources or form navigation are permitted, even if an assertion fails.
        writeFileSync(page, `<!doctype html><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; connect-src 'none'; form-action 'none'; base-uri 'none'"><div id="fixture"></div><pre id="native-result"></pre><script>${script.replaceAll('</script', '<\\/script')}</script>`);
        const run = spawnSync(browser, [
            '--headless=new', '--no-first-run', '--no-default-browser-check', '--disable-extensions',
            '--disable-background-networking', '--disable-component-update', '--disable-sync', '--disable-quic',
            '--proxy-server=http://127.0.0.1:9', '--proxy-bypass-list=<-loopback>',
            '--host-resolver-rules=MAP * ~NOTFOUND', `--user-data-dir=${join(directory, 'profile')}`,
            '--virtual-time-budget=1000', '--dump-dom', pathToFileURL(page).href,
        ], { encoding: 'utf8', windowsHide: true, timeout: 30000, maxBuffer: 8 * 1024 * 1024 });
        assert.ifError(run.error);
        assert.equal(run.status, 0, 'The isolated headless browser must exit successfully.');
        const encoded = run.stdout.match(/<pre id="native-result">([A-Za-z\d+/=]+)<\/pre>/)?.[1];
        assert.ok(encoded, 'Native browser observation is missing; no submission result was inferred.');
        const result = JSON.parse(Buffer.from(encoded, 'base64').toString('utf8'));
        assert.deepEqual(result.errors, []);
        observations = result.results;
        if (resultFile) writeFileSync(resultFile, JSON.stringify(result, null, 2));
    } finally {
        assert.ok(resolve(directory).startsWith(resolve(tmpdir()) + sep) && basename(directory).startsWith('assocmap-native-'));
        rmSync(directory, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 });
    }
});

for (const scenario of scenarios) {
    test(`native Association edit submits one empty Area: ${scenario}`, {
        skip: !fixture && 'Run the isolated AssociationManagementTest native form case to supply the real Blade fixture.',
    }, () => {
        const { initial, beforeSubmit, submitted } = observations.find(result => result.scenario === scenario);
        assert.notEqual(initial.area, '');
        assert.equal(initial.address, '');
        assert.equal(beforeSubmit.area, '');
        assert.equal(beforeSubmit.areaLabel, 'No Specific Area');
        assert.equal(beforeSubmit.areaControls, 1);
        assert.equal(beforeSubmit.address, 'S4-PERSISTENCE-TEST');
        assert.equal(beforeSubmit.municipality, initial.municipality);
        assert.equal(beforeSubmit.barangay, initial.barangay);
        assert.ok(submitted, 'Native constraint validation must allow the edit to submit.');
        assert.equal(submitted.method, 'post');
        assert.deepEqual(submitted.areaValues, ['']);
        const data = new URLSearchParams(submitted.urlEncoded);
        assert.deepEqual(data.getAll('area_id'), ['']);
        assert.equal(data.get('specific_address'), 'S4-PERSISTENCE-TEST');
        assert.equal(data.get('_method'), 'PATCH');
        assert.equal(data.get('municipality_id'), initial.municipality);
        assert.equal(data.get('barangay_id'), initial.barangay);
        assert.ok(![...data.keys()].some(key => key.startsWith('registration[')));
    });
}
