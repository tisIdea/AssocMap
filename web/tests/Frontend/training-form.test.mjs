import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { trainingFormFixture } from './support/training-form-fixture.mjs';
import { applyLookupResults } from '../../resources/js/pages/program-lookups.js';

const template = readFileSync(new URL('../../resources/views/trainings/partials/form.blade.php', import.meta.url), 'utf8');
const projectId = '00000000-0000-4000-8000-000000000101';
const associationId = '00000000-0000-4000-8000-000000000011';

function fixture({ eligible = false, initial = {}, restore = false, recorded = true } = {}) {
    const record = recorded ? { id: 'training-a', update_url: '/admin/trainings/training-a', related_project_id: projectId,
        related_project: 'Recorded <Project>', participants: [] } : null;
    const bindings = {
        '$editing': record,
        '$projectOptions': eligible ? [{ value: projectId, label: 'Recorded <Project>', association_id: associationId }] : [],
        '$memberOptions': [],
        '$initialValues': { association_id: associationId, saad_activity_id: '2', related_project_id: projectId,
            expected_updated_at: '2026-01-01T00:00:00.000000+00:00', ...initial },
        '$restore': restore,
        '$associations->isNotEmpty() && $activities->isNotEmpty()': true,
        "route($trainingPrefix.'.store')": '/admin/trainings',
        '$showEditor ?? false': false,
    };
    const script = template.match(/<script type="module">([\s\S]*?)<\/script>/)[1].replace(/\{\{ Illuminate\\Support\\Js::from\((.*?)\) \}\}/g, (_, expression) => {
        assert.ok(expression in bindings, `Unrecognized Training script binding: ${expression}`);
        return JSON.stringify(bindings[expression]);
    });
    const selects = { association_id: [[associationId, 'Association']], saad_activity_id: [['2', 'Training activity']], related_project_id: [['', 'No related project']] };
    const inputs = ['training_record_id', '_method', 'expected_updated_at', 'title', 'description', 'program_year', 'start_date', 'end_date', 'venue', 'conducted_by', 'training_cost', 'status', 'remarks'];
    return trainingFormFixture(`<form id="training-form">${Object.entries(selects).map(([name, options]) => `<select name="${name}">${options.map(([value, label]) => `<option value="${value}">${label}</option>`).join('')}</select>`).join('')}${inputs.map(name => `<input name="${name}" value="${name === '_method' ? 'PATCH' : ''}">`).join('')}</form><script type="module">${script}</script>`);
}

test('eligible recorded Project survives initialization and submission unchanged', () => {
    const state = fixture({ eligible: true }).snapshot();
    assert.equal(state.fields.related_project_id, projectId);
    assert.equal(state.fields._method, 'PATCH');
    assert.equal(state.fields.expected_updated_at, '2026-01-01T00:00:00.000000+00:00');
    assert.equal(state.notice.hidden, true);
});

test('retained unavailable Project survives initialization and submission with its accurate label', () => {
    const state = fixture().snapshot();
    assert.equal(state.fields.related_project_id, projectId);
    assert.match(state.projectLabel, /Recorded <Project>.*retained.*unavailable/i);
    assert.equal(state.notice.hidden, false);
    assert.match(state.notice.textContent, /preserved.*validation/i);
});

test('explicit removal submits empty and validation recovery keeps that choice', () => {
    const editor = fixture();
    editor.form.elements.related_project_id.value = '';
    assert.equal(editor.snapshot().fields.related_project_id, '');
    const recovered = fixture({ restore: true, initial: { related_project_id: '' } }).snapshot();
    assert.equal(recovered.fields.related_project_id, '');
    assert.equal(recovered.projectLabel, 'No related project');
});

test('changing the association explicitly clears the Project and removes the retained choice', () => {
    const editor = fixture();
    const association = editor.form.elements.association_id;
    association.add(new editor.Option('Another association', 'another-association'));
    association.value = 'another-association';
    association.dispatchEvent({ type: 'change' });
    assert.equal(editor.snapshot().fields.related_project_id, '');
    assert.deepEqual(editor.form.elements.related_project_id.options.map(option => option.value), ['']);
    assert.match(editor.notice.textContent, /Association changed/);
});

test('validation recovery preserves the retained Project and a rejected replacement without substituting another Project', () => {
    assert.equal(fixture({ restore: true }).snapshot().fields.related_project_id, projectId);
    const rejected = fixture({ restore: true, initial: { related_project_id: 'rejected-project' } }).snapshot();
    assert.equal(rejected.fields.related_project_id, 'rejected-project');
    assert.match(rejected.projectLabel, /unavailable.*review/i);
    assert.doesNotMatch(rejected.projectLabel, /Recorded/);
});

test('retained selection survives a bounded lookup that returns different Projects', () => {
    const editor = fixture();
    globalThis.Option = editor.Option;
    globalThis.CustomEvent = class { constructor(type, options) { this.type = type; Object.assign(this, options); } };
    const controls = { '[data-lookup-previous]': {}, '[data-lookup-next]': {}, '[data-lookup-status]': {} };
    const picker = { dataset: { programLookup: 'projects', lookupTarget: 'related_project_id' }, closest: () => editor.form,
        querySelector: selector => controls[selector] };
    applyLookupResults(picker, { options: [{ value: 'another-project', label: 'Another Project', association_id: associationId }], page: 2, has_more: false });
    assert.equal(editor.snapshot().fields.related_project_id, projectId);
    assert.match(editor.snapshot().projectLabel, /retained.*unavailable/i);
});

test('a new Training never inherits the retained Project label from an existing record', () => {
    const state = fixture({ recorded: false, restore: true }).snapshot();
    assert.equal(state.fields.related_project_id, projectId);
    assert.match(state.projectLabel, /unavailable.*review/i);
    assert.doesNotMatch(state.projectLabel, /Recorded/);
});
