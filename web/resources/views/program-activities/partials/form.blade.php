@php
    $activityPrefix = $activityPrefix ?? 'admin.program-activities';
    $isFieldOfficer = $activityPrefix === 'field-officer.program-activities';
    $statuses = ['planned' => 'Planned', 'ongoing' => 'Ongoing', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    $editing = $editRow ?? null;
    $restore = $errors->any() && old('program_activity_form') === 'editor'
        && (string) old('program_activity_record_id', '') === (string) ($editing['id'] ?? '');
    $projectOptions = $projects->map(fn ($project) => ['value' => $project->id, 'label' => $project->title, 'association_id' => $project->association_id])->values();
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
    $initialValues = $restore ? old() : ($editing ? array_replace($editing, ['status' => $editing['status_value']]) : []);
@endphp
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
                    @php($value = $initialValues[$field['name']] ?? ($field['name'] === 'status' ? 'planned' : ''))
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
        <div class="modal-actions">@if($editing)<a class="button" href="{{ route($activityPrefix.'.show', $editing['id']) }}">Cancel</a>@else<button type="button" class="button" data-close-dialog>Cancel</button>@endif<button type="submit" class="button button-primary">Save Program Activity</button></div>
    </form>
<script type="module">
    const editingRecord = {{ Illuminate\Support\Js::from($editing) }};
    const projects = {{ Illuminate\Support\Js::from($projectOptions) }};
    const initial = {{ Illuminate\Support\Js::from($initialValues) }};
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
        if (!editingRecord) document.getElementById('program-activity-editor-title').textContent = 'Add Program Activity';
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
        if (!event.target.closest('[data-program-activity-create]')) return;
        populate({});
        form.querySelector('[data-program-activity-errors]')?.remove();
        form.querySelectorAll('.field-error').forEach(element => element.remove());
        form.querySelectorAll('[aria-invalid]').forEach(element => { element.removeAttribute('aria-invalid'); element.removeAttribute('aria-describedby'); });
        form.elements.saad_activity_id.focus();
    });
    populate(initial, editingRecord);
    if (restore && !editingRecord) document.getElementById('program-activity-editor').dataset.feedbackRestore = 'true';
</script>
