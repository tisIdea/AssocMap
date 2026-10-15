import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/monitoring/partials/editor-script.blade.php', import.meta.url), 'utf8').replace(/\r\n/g, '\n');
const helper = source.match(/^    function refreshQuarterAvailability\(\) \{[\s\S]*?^    \}/m)?.[0];
assert.ok(helper, 'The monitoring editor must contain its quarter availability helper.');
assert.ok(!helper.includes('{{'), 'The tested helper must contain executable JavaScript only.');
const submitGuard = source.match(/form\.addEventListener\('submit', event => \{\n([\s\S]*?)        refreshUnique\('production'\)/)?.[1];
assert.ok(submitGuard, 'Monitoring submission must check quarter availability.');

function editor(periods = [], editingRecord = null) {
    const options = [{ value: '', textContent: 'Select a quarter…', dataset: {}, disabled: false },
        ...[11, 22, 33, 44].map((id, index) => ({ value: String(id), textContent: 'Q' + (index + 1), dataset: {}, disabled: false }))];
    const quarter = { value: '', options, disabled: Boolean(editingRecord), validityMessage: '',
        setCustomValidity(message) { this.validityMessage = message; } };
    const project = { value: 'project-a' };
    const year = { value: '2026' };
    const button = { disabled: false };
    const status = { hidden: false, textContent: '', focused: false, focus() { this.focused = true; } };
    const form = { elements: { quarter_id: quarter, project_id: project, year },
        querySelector: () => button };
    const context = {
        document: { getElementById: () => status },
        form, occupiedPeriods: periods, editingRecord, periodValues: { quarter_id: '' },
        selectedProject: () => ['project-a', 'project-b'].includes(project.value) ? { id: project.value } : null,
    };
    // Execute only the actual helper and submission guard, without Blade, network, or application bootstrap.
    runInNewContext(helper + '\nfunction checkSubmission(event) {\n' + submitGuard + '\n}\n', context);
    return {
        quarter, project, year, button, status,
        refresh: () => context.refreshQuarterAvailability(),
        submit() {
            const event = { prevented: false, preventDefault() { this.prevented = true; } };
            context.checkSubmission(event);
            return event;
        },
        available: () => options.filter(option => option.value && !option.disabled).map(option => option.value),
    };
}

test('quarters are blocked by project ID and monitoring year, using actual quarter IDs', () => {
    const fixture = editor([
        { project_id: 'project-a', year: 2026, quarter_id: 11 },
        { project_id: 'project-a', year: 2025, quarter_id: 22 },
        { project_id: 'project-b', year: 2026, quarter_id: 33 },
    ]);
    assert.equal(fixture.refresh(), true);
    assert.deepEqual(fixture.available(), ['22', '33', '44']);
    assert.match(fixture.quarter.options[1].textContent, /already recorded/);
});

test('changing project or year recalculates available quarters', () => {
    const fixture = editor([{ project_id: 'project-a', year: 2026, quarter_id: 11 },
        { project_id: 'project-b', year: 2026, quarter_id: 22 }]);
    fixture.refresh();
    fixture.project.value = 'project-b';
    fixture.refresh();
    assert.deepEqual(fixture.available(), ['11', '33', '44']);
    fixture.year.value = '2027';
    fixture.refresh();
    assert.deepEqual(fixture.available(), ['11', '22', '33', '44']);
});

test('a newly unavailable selected quarter is cleared and an available selection is preserved', () => {
    const fixture = editor([{ project_id: 'project-a', year: 2026, quarter_id: 11 }]);
    fixture.quarter.value = '11';
    fixture.refresh();
    assert.equal(fixture.quarter.value, '');
    fixture.quarter.value = '22';
    fixture.refresh();
    assert.equal(fixture.quarter.value, '22');
});

test('all occupied quarters disable creation and explain how to access existing records', () => {
    const fixture = editor([11, 22, 33, 44].map(quarter_id => ({ project_id: 'project-a', year: 2026, quarter_id })));
    assert.equal(fixture.refresh(), false);
    assert.deepEqual(fixture.available(), []);
    assert.equal(fixture.quarter.disabled, true);
    assert.equal(fixture.button.disabled, true);
    assert.match(fixture.status.textContent, /No quarters remain available/);
    assert.match(fixture.status.textContent, /View or edit.*Monitoring dashboard/);
    assert.equal(fixture.submit().prevented, true);
    assert.equal(fixture.status.focused, true);
});

test('missing projects and invalid years cannot proceed', () => {
    const fixture = editor();
    fixture.project.value = '';
    assert.equal(fixture.refresh(), false);
    fixture.project.value = 'project-a';
    for (const year of ['', '1999', '2101', '2026.5']) {
        fixture.year.value = year;
        assert.equal(fixture.refresh(), false);
        assert.equal(fixture.button.disabled, true);
    }
});

test('available quarters restore creation after switching away from a full year', () => {
    const fixture = editor([11, 22, 33, 44].map(quarter_id => ({ project_id: 'project-a', year: 2026, quarter_id })));
    fixture.refresh();
    fixture.year.value = '2027';
    assert.equal(fixture.refresh(), true);
    assert.equal(fixture.quarter.disabled, false);
    assert.equal(fixture.button.disabled, false);
    assert.equal(fixture.quarter.validityMessage, '');
});

test('occupied periods do not block editing an existing record', () => {
    const fixture = editor([{ project_id: 'project-a', year: 2026, quarter_id: 11 }], { id: 'existing-record' });
    fixture.quarter.value = '11';
    assert.equal(fixture.refresh(), true);
    assert.equal(fixture.quarter.value, '11');
    assert.equal(fixture.quarter.disabled, true);
    assert.equal(fixture.button.disabled, false);
    assert.equal(fixture.status.hidden, true);
});
