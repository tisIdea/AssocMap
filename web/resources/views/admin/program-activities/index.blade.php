@php
    $catalog = $catalog ?? require resource_path('ui/admin.php');
    $activityPrefix = $activityPrefix ?? 'admin.program-activities';
    $isFieldOfficer = $activityPrefix === 'field-officer.program-activities';
    $rows = collect($activityRows);
    $statuses = ['planned' => 'Planned', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    $restore = $errors->any() && old('program_activity_form') === 'editor';
    $editing = $restore ? $rows->first(fn ($row) => (string) $row['id'] === (string) old('program_activity_record_id') && !$row['archived'] && !$row['project_finalized']) : null;
    $restore = $restore && (!old('program_activity_record_id') || $editing);
    $recordModels = $records->keyBy('id');
    $projectOptions = $projects->map(fn ($project) => ['value' => $project->id, 'label' => $project->title, 'association_id' => $project->association_id])->values();
    $columns = ['title' => 'Title', 'activity' => 'SAAD Activity', 'association' => 'Association', 'municipality' => 'Municipality', 'related_project' => 'Related Project', 'program_year' => 'Program Year', 'activity_date' => 'Activity Date', 'venue' => 'Venue', 'conducted_by' => 'Conducted By'];
    $sections = [
        'Classification' => [
            ['name' => 'saad_activity_id', 'label' => 'SAAD Activity', 'type' => 'select', 'required' => true, 'choices' => $activities->map(fn ($activity) => ['value' => $activity->id, 'label' => $activity->name])->all()],
        ],
        'Scope and Related Project' => [
            ['name' => 'association_id', 'label' => 'Association', 'type' => 'select', 'required' => $isFieldOfficer, 'choices' => $associations->map(fn ($association) => ['value' => $association->id, 'label' => $association->name])->all(), 'empty_label' => $isFieldOfficer ? 'Select assigned association' : 'Program-wide (no association)'],
            ['name' => 'related_project_id', 'label' => 'Related Project', 'type' => 'select', 'choices' => [], 'empty_label' => 'No related project'],
        ],
        'Activity Information' => [
            ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'maxlength' => 255],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea'],
            ['name' => 'program_year', 'label' => 'Program Year', 'type' => 'number', 'required' => true, 'min' => 2000, 'max' => 2100, 'step' => 1],
            ['name' => 'activity_date', 'label' => 'Activity Date', 'type' => 'date', 'required' => true],
            ['name' => 'venue', 'label' => 'Venue', 'type' => 'text', 'maxlength' => 255],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'choices' => collect($statuses)->map(fn ($label, $value) => compact('value', 'label'))->all()],
            ['name' => 'conducted_by', 'label' => 'Conducted By', 'type' => 'text', 'maxlength' => 255],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea'],
        ],
    ];
@endphp
@extends($activityLayout ?? 'layouts.admin')
@section('content')
<div data-workspace-module="program-activities" data-workspace-title="Program Activities">
    <header class="page-header">
        <div><div class="eyebrow">{{ $isFieldOfficer ? 'Field Officer' : 'Program Operations' }}</div><h1 tabindex="-1">Program Activities</h1><p>{{ $isFieldOfficer ? 'Manage program activities for your assigned associations.' : 'Manage association-specific and program-wide activities.' }}</p></div>
    </header>
    @if(!$isFieldOfficer)
        @if($errors->any() && !$restore)<div class="notice notice-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @endif
    <section class="card list-card" aria-label="Program activities">
        <div class="table-toolbar">
            <form method="GET" action="{{ route($activityPrefix.'.index') }}">
                <div class="field"><label for="program-activity-status-filter">Activity Status</label><select id="program-activity-status-filter" name="status"><option value="">All current activities</option>@foreach($statuses + ['archived' => 'Archived'] as $value => $label)<option value="{{ $value }}" @selected($statusFilter === $value)>{{ $label }}</option>@endforeach</select></div>
                <button type="submit" class="button">Apply Filter</button>
            </form>
            <button type="button" class="button button-primary" data-program-activity-create data-dialog="program-activity-editor" @disabled($activities->isEmpty() || ($isFieldOfficer && $associations->isEmpty()))><x-icon name="plus" />Add Program Activity</button>
        </div>
        <div class="table-scroll" role="region" aria-label="Program activity list" tabindex="0">
            <table class="data-table"><caption class="sr-only">Program activities and implementation status</caption>
                <thead><tr>@foreach($columns as $label)<th scope="col">{{ $label }}</th>@endforeach<th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                @foreach($rows as $row)
                    <tr>
                        @foreach($columns as $key => $label)
                            <td @class(['primary-cell' => $key === 'title'])>{{ $key === 'association' && !$isFieldOfficer && !$row['association_id'] ? 'Program-wide' : (filled($row[$key] ?? null) ? $row[$key] : '—') }}</td>
                        @endforeach
                        <td><span class="badge badge-{{ $row['archived'] ? 'neutral' : ($row['status_value'] === 'completed' ? 'success' : ($row['status_value'] === 'cancelled' ? 'danger' : 'warning')) }}">{{ $row['archived'] ? 'Archived' : $row['status'] }}</span></td>
                        <td><div class="row-actions">
                            <button type="button" class="text-button" data-dialog="program-activity-view-{{ $row['id'] }}" aria-label="View {{ $row['title'] }}">View</button>
                            @if(!$row['project_finalized'])
                            @if(!$row['archived'])<button type="button" class="text-button" data-program-activity-edit="{{ $row['id'] }}" data-dialog="program-activity-editor" aria-label="Edit {{ $row['title'] }}">Edit</button>@endif
                            <button type="button" class="text-button {{ !$row['archived'] ? 'text-danger' : '' }}" data-dialog="program-activity-action-{{ $row['id'] }}" aria-label="{{ $row['archived'] ? 'Unarchive' : 'Archive' }} {{ $row['title'] }}">{{ $row['archived'] ? 'Unarchive' : 'Archive' }}</button>
                            @else<span>Read-only: project finalized</span>@endif
                        </div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if($rows->isEmpty())<x-empty-state title="No program activities found." message="Try another status filter or add a program activity." />@endif
        <footer class="table-footer"><span>{{ $rows->count() }} {{ $rows->count() === 1 ? 'activity' : 'activities' }}</span></footer>
    </section>
    @if($activities->isEmpty())<p class="notice">No active SAAD activities are available for this module.</p>@endif
    @if($associations->isEmpty())<p class="notice">{{ $isFieldOfficer ? 'No assigned active associations are available for creating activities.' : 'No active associations are available. You can still create program-wide activities.' }}</p>@endif
</div>
@foreach($rows as $row)
    @php($record = $recordModels->get($row['id']))
    <x-modal :id="'program-activity-view-'.$row['id']" title="Program Activity Details">
        <dl class="detail-grid">
            @foreach($columns + ['description' => 'Description', 'status' => 'Status', 'remarks' => 'Remarks'] as $key => $label)
                <div><dt>{{ $label }}</dt><dd>{{ $key === 'association' && !$isFieldOfficer && !$row['association_id'] ? 'Program-wide' : (filled($row[$key] ?? null) ? $row[$key] : '—') }}</dd></div>
            @endforeach
            <div><dt>Archived</dt><dd>{{ $row['archived'] ? 'Yes' : 'No' }}</dd></div>
            @if($record?->archived_at)<div><dt>Archived At</dt><dd>{{ $record->archived_at->format('M j, Y g:i A') }}</dd></div>@endif
        </dl>
        @if($row['project_finalized'])<p class="notice">The linked project is completed or terminated. This activity is permanently read-only.</p>@endif
        @if(!$row['project_finalized'] && $row['archived'])<p class="notice">This activity is archived and read-only. Unarchive it before editing.</p>@endif
        <div class="modal-actions"><button type="button" class="button" data-close-dialog>Close</button></div>
    </x-modal>
    @if(!$row['project_finalized'])
    <x-modal :id="'program-activity-action-'.$row['id']" :title="$row['archived'] ? 'Unarchive Program Activity' : 'Archive Program Activity'">
        <p>{{ $row['archived'] ? 'Restore' : 'Archive' }} <strong>{{ $row['title'] }}</strong>? {{ $row['archived'] ? 'The activity will return to the current list.' : 'Its details will remain available for viewing.' }}</p>
        <form method="POST" action="{{ $row['archived'] ? $row['unarchive_url'] : $row['archive_url'] }}">
            @csrf @method('PATCH')
            <div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button {{ $row['archived'] ? 'button-primary' : 'button-danger' }}">{{ $row['archived'] ? 'Unarchive Activity' : 'Archive Activity' }}</button></div>
        </form>
    </x-modal>
    @endif
@endforeach
<x-modal id="program-activity-editor" :title="$editing ? 'Edit Program Activity' : 'Add Program Activity'">
    <form method="POST" action="{{ $editing['update_url'] ?? route($activityPrefix.'.store') }}" id="program-activity-form" @if($restore) data-feedback-error-bag="default" @endif>
        @csrf
        <input type="hidden" name="program_activity_form" value="editor">
        <input type="hidden" name="program_activity_record_id" value="{{ $editing['id'] ?? '' }}">
        <input type="hidden" name="_method" value="PATCH" @disabled(!$editing)>
        <p>Fields marked * are required.</p>
        @if($restore)<div class="notice notice-error" role="alert" data-program-activity-errors>@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @foreach($sections as $heading => $fields)
            <section class="form-section">
                <div class="section-heading"><h3>{{ $heading }}</h3></div>
                @if($heading === 'Scope and Related Project')<p class="section-help">{{ $isFieldOfficer ? 'Choose an assigned association. Related projects must belong to that association.' : 'Leave Association as Program-wide to record an activity without an association. Related projects require an association.' }}</p>@endif
                <div class="form-grid">
                @foreach($fields as $field)
                    @php($value = $restore ? old($field['name'], '') : ($field['name'] === 'status' ? 'planned' : ''))
                    <div class="field {{ $field['type'] === 'textarea' ? 'field-wide' : '' }}">
                        <label for="program-activity-{{ $field['name'] }}">{{ $field['label'] }} <span class="field-hint">{{ ($field['required'] ?? false) ? '*' : 'Optional' }}</span></label>
                        @if($field['type'] === 'select')
                            <select id="program-activity-{{ $field['name'] }}" name="{{ $field['name'] }}" @required($field['required'] ?? false) @if($restore && $errors->has($field['name'])) aria-invalid="true" aria-describedby="program-activity-{{ $field['name'] }}-error" @endif>
                                <option value="">{{ $field['empty_label'] ?? 'Select…' }}</option>
                                @foreach($field['choices'] as $choice)<option value="{{ $choice['value'] }}" @selected((string) $value === (string) $choice['value'])>{{ $choice['label'] }}</option>@endforeach
                            </select>
                        @elseif($field['type'] === 'textarea')
                            <textarea id="program-activity-{{ $field['name'] }}" name="{{ $field['name'] }}" rows="3" @if($restore && $errors->has($field['name'])) aria-invalid="true" aria-describedby="program-activity-{{ $field['name'] }}-error" @endif>{{ $value }}</textarea>
                        @else
                            <input id="program-activity-{{ $field['name'] }}" name="{{ $field['name'] }}" type="{{ $field['type'] }}" value="{{ $value }}" @required($field['required'] ?? false) @foreach(['maxlength', 'min', 'max', 'step'] as $attribute) @if(isset($field[$attribute])) {{ $attribute }}="{{ $field[$attribute] }}" @endif @endforeach @if($restore && $errors->has($field['name'])) aria-invalid="true" aria-describedby="program-activity-{{ $field['name'] }}-error" @endif>
                        @endif
                        @if($restore) @error($field['name'])<span class="field-error" id="program-activity-{{ $field['name'] }}-error">{{ $message }}</span>@enderror @endif
                    </div>
                @endforeach
                </div>
            </section>
        @endforeach
        <p class="notice" id="program-activity-choice-notice" role="status" hidden></p>
        <div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button button-primary">Save Program Activity</button></div>
    </form>
</x-modal>
<script type="module">
    const rows = {{ Illuminate\Support\Js::from($rows->values()) }};
    const projects = {{ Illuminate\Support\Js::from($projectOptions) }};
    const initial = {{ Illuminate\Support\Js::from($restore ? old() : []) }};
    const restore = {{ Illuminate\Support\Js::from($restore) }};
    const createUrl = {{ Illuminate\Support\Js::from(route($activityPrefix.'.store')) }};
    const form = document.getElementById('program-activity-form');
    const association = form.elements.association_id;
    const project = form.elements.related_project_id;
    const notice = document.getElementById('program-activity-choice-notice');
    const submit = form.querySelector('button[type="submit"]');
    let unavailableRecord = false;
    let unavailableAssociation = false;
    function projectChoices(selected = '') {
        project.replaceChildren(new Option('No related project', ''));
        if (association.value) {
            projects.filter(record => String(record.association_id) === association.value).forEach(record => project.add(new Option(record.label, record.value)));
        }
        project.value = String(selected);
        if (project.selectedIndex < 0) project.value = '';
        project.disabled = !association.value;
        return Boolean(selected && project.value !== String(selected));
    }
    function populate(values, record = null) {
        form.reset();
        form.action = record?.update_url ?? createUrl;
        form.elements._method.disabled = !record;
        form.elements.program_activity_record_id.value = record?.id ?? '';
        document.getElementById('program-activity-editor-title').textContent = record ? 'Edit Program Activity' : 'Add Program Activity';
        for (const key of ['saad_activity_id', 'association_id', 'title', 'description', 'program_year', 'activity_date', 'venue', 'status', 'conducted_by', 'remarks']) {
            form.elements[key].value = values[key] ?? (key === 'status' ? 'planned' : '');
        }
        unavailableRecord = Boolean(values.program_activity_record_id && !record);
        unavailableAssociation = Boolean(values.association_id && !association.value);
        const unavailableProject = projectChoices(values.related_project_id ?? '');
        const unavailableActivity = Boolean(values.saad_activity_id && !form.elements.saad_activity_id.value);
        submit.disabled = unavailableRecord || unavailableAssociation;
        notice.hidden = !(unavailableRecord || unavailableAssociation || unavailableProject || unavailableActivity);
        notice.textContent = unavailableRecord
            ? 'This activity is no longer available for editing in the current list. Close this dialog and reopen an available activity.'
            : 'Some previous selections are no longer available. Review the SAAD activity, association, and related project before saving. Choose an available scope to continue.';
    }
    association.addEventListener('change', () => {
        projectChoices();
        unavailableAssociation = false;
        submit.disabled = unavailableRecord;
        notice.hidden = false;
        notice.textContent = association.value ? 'Association changed. Select a related project from this association if needed.' : 'No association selected. Related Project has been cleared.';
    });
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-program-activity-create], [data-program-activity-edit]');
        if (!button) return;
        const record = button.hasAttribute('data-program-activity-edit') ? rows.find(row => String(row.id) === button.dataset.programActivityEdit && !row.archived && !row.project_finalized) : null;
        if (button.hasAttribute('data-program-activity-edit') && !record) return;
        populate(record ? {...record, status: record.status_value} : {}, record);
        form.querySelector('[data-program-activity-errors]')?.remove();
        form.querySelectorAll('.field-error').forEach(element => element.remove());
        form.querySelectorAll('[aria-invalid]').forEach(element => { element.removeAttribute('aria-invalid'); element.removeAttribute('aria-describedby'); });
        form.elements.saad_activity_id.focus();
    });
    populate(initial, restore ? rows.find(row => String(row.id) === String(initial.program_activity_record_id) && !row.archived) : null);
    if (restore) document.getElementById('program-activity-editor').dataset.feedbackRestore = 'true';
</script>
@endsection
